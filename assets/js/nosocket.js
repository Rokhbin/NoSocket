const DEFAULTS = Object.freeze({
  endpoint: "/nosocket/poll",
  namespace: "default",
  normalInterval: 30_000,
  activeInterval: 10_000,
  burstInterval: 2_000,
  burstDuration: 30_000,
  activeWindow: 60_000,
  leaseDuration: 8_000,
  heartbeatInterval: 3_000,
  requestTimeout: 12_000,
  tokenRefreshWindow: 30_000,
  jitterRatio: 0.15,
});

function randomId() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
}

function parseJson(value, fallback = null) {
  try { return JSON.parse(value); } catch { return fallback; }
}

function tokenExpiry(token) {
  try {
    const unpadded = token.split(".")[0].replace(/-/g, "+").replace(/_/g, "/");
    const encoded = unpadded.padEnd(Math.ceil(unpadded.length / 4) * 4, "=");
    const claims = JSON.parse(globalThis.atob(encoded));
    return Number.isInteger(claims.exp) ? claims.exp * 1000 : 0;
  } catch {
    return 0;
  }
}

export class CrossTabBus {
  constructor(name, env = globalThis) {
    this.env = env;
    this.key = `nosocket:${name}:message`;
    this.listeners = new Set();
    this.channel = typeof env.BroadcastChannel === "function"
      ? new env.BroadcastChannel(`nosocket:${name}`)
      : null;
    this.onMessage = (event) => this.deliver(parseJson(event.newValue));
    if (this.channel) this.channel.onmessage = (event) => this.deliver(event.data);
    else env.addEventListener?.("storage", this.onMessage);
  }

  post(message) {
    const envelope = { ...message, messageId: randomId(), sentAt: Date.now() };
    if (this.channel) return this.channel.postMessage(envelope);
    try {
      this.env.localStorage.setItem(this.key, JSON.stringify(envelope));
      this.env.localStorage.removeItem(this.key);
    } catch {}
  }

  subscribe(listener) {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }

  deliver(message) {
    if (message) for (const listener of this.listeners) listener(message);
  }

  close() {
    this.channel?.close();
    this.env.removeEventListener?.("storage", this.onMessage);
    this.listeners.clear();
  }
}

export class NoSocketClient {
  constructor(options = {}, env = globalThis) {
    this.options = { ...DEFAULTS, ...options };
    this.env = env;
    this.id = randomId();
    this.bus = new CrossTabBus(this.options.namespace, env);
    this.handlers = new Map();
    this.channels = new Map();
    this.peerStates = new Map();
    this.cursors = this.readObject("cursors");
    this.paused = new Set();
    this.lastActivity = Date.now();
    this.burstUntil = 0;
    this.failureCount = 0;
    this.timer = null;
    this.tickInFlight = false;
    this.heartbeatTimer = null;
    this.isLeader = false;
    this.started = false;
    this.token = this.options.token ?? "";
    this.tokenExpiresAt = tokenExpiry(this.token);
    this.tokenFingerprint = "";
    this.lockPending = false;
    this.releaseLock = null;
    this.unbindBus = this.bus.subscribe((message) => this.receive(message));
    this.boundActivity = () => { this.lastActivity = Date.now(); };
    this.boundOnline = () => this.schedule(0);
    this.boundVisibility = () => this.onVisibilityChange();
  }

  subscribe(channel, options = {}) {
    this.assertName(channel, "Channel");
    const replay = options.replay ?? "live";
    if (!["live", "retained"].includes(replay)) throw new TypeError("Replay must be live or retained.");
    this.channels.set(channel, { replay });
    this.broadcastState();
    if (this.started) this.schedule(0);
    return () => {
      this.channels.delete(channel);
      this.paused.delete(channel);
      this.broadcastState();
      this.schedule(0);
    };
  }

  on(event, callback) {
    this.assertName(event, "Event");
    if (typeof callback !== "function") throw new TypeError("NoSocket callback must be a function.");
    const callbacks = this.handlers.get(event) ?? new Set();
    callbacks.add(callback);
    this.handlers.set(event, callbacks);
    return () => callbacks.delete(callback);
  }

  start() {
    if (this.started) return this;
    this.started = true;
    for (const type of ["pointerdown", "keydown", "focus"]) {
      this.env.addEventListener?.(type, this.boundActivity, { passive: true });
    }
    this.env.addEventListener?.("online", this.boundOnline);
    this.env.document?.addEventListener?.("visibilitychange", this.boundVisibility);
    this.broadcastState();
    this.refreshLeadership();
    this.schedule(0);
    this.heartbeatTimer = this.env.setInterval(() => this.heartbeat(), this.options.heartbeatInterval);
    return this;
  }

  stop() {
    this.started = false;
    this.env.clearTimeout(this.timer);
    this.env.clearInterval(this.heartbeatTimer);
    this.timer = null;
    this.releaseLeadership();
    for (const type of ["pointerdown", "keydown", "focus"]) {
      this.env.removeEventListener?.(type, this.boundActivity);
    }
    this.env.removeEventListener?.("online", this.boundOnline);
    this.env.document?.removeEventListener?.("visibilitychange", this.boundVisibility);
    this.unbindBus();
    this.bus.close();
  }

  async tick() {
    if (!this.started || this.tickInFlight) return;
    this.tickInFlight = true;
    try {
      this.refreshLeadership();
      const cooldown = this.readNumber("cooldown", 0);
      if (!this.isLeader || this.env.navigator?.onLine === false || cooldown > Date.now()) {
        this.schedule(Math.max(this.options.heartbeatInterval, cooldown - Date.now()));
        return;
      }

      const subscriptions = this.allSubscriptions();
      const channels = Object.keys(subscriptions);
      if (channels.length === 0) return this.schedule(this.interval());

      try {
        await this.ensureToken(channels, "poll");
        let result;
        try {
          result = await this.poll(subscriptions);
        } catch (error) {
          if (error.status !== 403 || typeof this.options.tokenProvider !== "function") throw error;
          await this.ensureToken(channels, "forbidden", true);
          result = await this.poll(subscriptions);
        }
        this.failureCount = 0;
        this.accept(result.events ?? []);
        this.applyCursors(result.cursors ?? {});
        for (const channel of result.resync_required ?? []) this.requireResync(channel);
        if ((result.events ?? []).length > 0) this.burstUntil = Date.now() + this.options.burstDuration;
        this.bus.post({ type: "cursors", source: this.id, cursors: this.cursors });
        this.schedule(result.has_more ? 0 : this.interval());
      } catch (error) {
        this.failureCount += 1;
        const delay = this.backoff(error.status, error.retryAfter);
        this.writeNumber("cooldown", Date.now() + delay);
        this.bus.post({ type: "cooldown", source: this.id, until: Date.now() + delay, status: error.status ?? 0 });
        this.schedule(delay);
      }
    } finally {
      this.tickInFlight = false;
    }
  }

  async ensureToken(channels, reason, force = false) {
    const fingerprint = [...channels].sort().join(",");
    const expiring = this.tokenExpiresAt > 0 && this.tokenExpiresAt <= Date.now() + this.options.tokenRefreshWindow;
    if (!force && this.token && !expiring && this.tokenFingerprint === fingerprint) return;
    if (typeof this.options.tokenProvider !== "function") {
      if (!this.token) throw new Error("NoSocket requires token or tokenProvider.");
      this.tokenFingerprint = fingerprint;
      return;
    }
    const provided = await this.options.tokenProvider({ channels: [...channels].sort(), reason });
    this.token = typeof provided === "string" ? provided : provided?.token;
    if (!this.token) throw new Error("NoSocket tokenProvider did not return a token.");
    this.tokenExpiresAt = typeof provided === "object" && provided.expiresAt
      ? new Date(provided.expiresAt).getTime()
      : tokenExpiry(this.token);
    this.tokenFingerprint = fingerprint;
  }

  async poll(subscriptions) {
    const controller = new AbortController();
    const timeout = this.env.setTimeout(() => controller.abort(), this.options.requestTimeout);
    const headers = { Accept: "application/json", "Content-Type": "application/json", Authorization: `Bearer ${this.token}` };
    if (this.options.csrfToken) headers["X-CSRF-Token"] = this.options.csrfToken;
    try {
      const response = await this.env.fetch(new URL(this.options.endpoint, this.env.location?.href ?? "http://localhost"), {
        method: "POST",
        headers,
        credentials: "same-origin",
        body: JSON.stringify({ subscriptions }),
        signal: controller.signal,
      });
      if (!response.ok) {
        const error = new Error(`NoSocket poll failed with HTTP ${response.status}.`);
        error.status = response.status;
        error.retryAfter = this.retryAfter(response.headers?.get?.("Retry-After"));
        throw error;
      }
      return await response.json();
    } finally {
      this.env.clearTimeout(timeout);
    }
  }

  accept(events) {
    for (const event of events) {
      const cursor = this.cursors[event?.channel];
      if (!event || !Number.isInteger(event.id) || (Number.isInteger(cursor) && event.id <= cursor)) continue;
      this.dispatch(event);
      this.cursors[event.channel] = event.id;
      this.writeObject("cursors", this.cursors);
      this.bus.post({ type: "event", source: this.id, event });
    }
  }

  applyCursors(cursors) {
    for (const [channel, cursor] of Object.entries(cursors)) {
      if (Number.isInteger(cursor) && (!Number.isInteger(this.cursors[channel]) || cursor > this.cursors[channel])) {
        this.cursors[channel] = cursor;
      }
    }
    this.writeObject("cursors", this.cursors);
  }

  dispatch(event) {
    for (const callback of this.handlers.get(event.event) ?? []) callback(event.payload, event);
    for (const callback of this.handlers.get("*") ?? []) callback(event.payload, event);
  }

  requireResync(channel, broadcast = true) {
    if (this.paused.has(channel)) return;
    this.paused.add(channel);
    const payload = { channel };
    this.dispatch({ id: 0, channel, event: "nosocket.resync_required", payload });
    if (broadcast) this.bus.post({ type: "resync-required", source: this.id, channel });
    if (typeof this.options.onResync === "function") {
      Promise.resolve(this.options.onResync(payload)).then(() => this.resync(channel)).catch(() => {});
    }
  }

  resync(channel) {
    this.assertName(channel, "Channel");
    delete this.cursors[channel];
    this.paused.delete(channel);
    this.writeObject("cursors", this.cursors);
    this.broadcastState();
    this.schedule(0);
  }

  receive(message) {
    if (message.source === this.id) return;
    if (message.type === "state" && message.channels) {
      const previous = this.peerStates.get(message.source);
      const subscriptionsChanged = !previous
        || this.subscriptionFingerprint(previous.channels) !== this.subscriptionFingerprint(message.channels);
      this.peerStates.set(message.source, { ...message, seenAt: Date.now() });
      if (this.isLeader && subscriptionsChanged) this.schedule(0);
    }
    if (message.type === "event") this.accept([message.event]);
    if (message.type === "cursors") this.applyCursors(message.cursors ?? {});
    if (message.type === "resync-required") this.requireResync(message.channel, false);
    if (message.type === "cooldown" && Number.isInteger(message.until)) {
      this.writeNumber("cooldown", Math.max(message.until, this.readNumber("cooldown", 0)));
    }
  }

  heartbeat() {
    if (!this.started) return;
    this.broadcastState();
    this.refreshLeadership();
    if (this.isLeader && !this.supportsWebLocks()) {
      this.writeLease({ owner: this.id, expiresAt: Date.now() + this.options.leaseDuration });
    }
    const staleAt = Date.now() - this.options.leaseDuration * 2;
    for (const [id, peer] of this.peerStates) if (peer.seenAt < staleAt) this.peerStates.delete(id);
  }

  broadcastState() {
    this.bus.post({
      type: "state",
      source: this.id,
      channels: Object.fromEntries(this.channels),
      visible: this.isVisible(),
      lastActivity: this.lastActivity,
    });
  }

  allSubscriptions() {
    const subscriptions = {};
    const add = (channel, config = {}) => {
      if (this.paused.has(channel)) return;
      const replay = subscriptions[channel]?.replay === "retained" || config.replay === "retained" ? "retained" : "live";
      subscriptions[channel] = {
        cursor: Number.isInteger(this.cursors[channel]) ? this.cursors[channel] : null,
        replay,
      };
    };
    for (const [channel, config] of this.channels) add(channel, config);
    for (const peer of this.peerStates.values()) {
      for (const [channel, config] of Object.entries(peer.channels ?? {})) add(channel, config);
    }
    return subscriptions;
  }

  subscriptionFingerprint(channels = {}) {
    return Object.entries(channels)
      .sort(([left], [right]) => left.localeCompare(right))
      .map(([channel, config]) => `${channel}:${config?.replay === "retained" ? "retained" : "live"}`)
      .join(",");
  }

  refreshLeadership() {
    if (!this.isVisible() && this.hasVisiblePeer()) {
      if (this.isLeader) this.releaseLeadership();
      return;
    }
    if (this.supportsWebLocks()) return this.acquireWebLock();
    const now = Date.now();
    const lease = this.readLease();
    if (!lease || lease.expiresAt <= now || lease.owner === this.id) {
      this.writeLease({ owner: this.id, expiresAt: now + this.options.leaseDuration });
      this.isLeader = this.readLease()?.owner === this.id;
    } else {
      this.isLeader = false;
    }
  }

  acquireWebLock() {
    if (this.isLeader || this.lockPending || !this.started) return;
    this.lockPending = true;
    this.env.navigator.locks.request(this.storageKey("leader"), { ifAvailable: true }, (lock) => {
      this.lockPending = false;
      if (!lock || !this.started) return;
      this.isLeader = true;
      this.schedule(0);
      return new Promise((resolve) => { this.releaseLock = resolve; });
    }).catch(() => {
      this.lockPending = false;
      this.isLeader = false;
    });
  }

  releaseLeadership() {
    this.releaseLock?.();
    this.releaseLock = null;
    if (this.readLease()?.owner === this.id) {
      try { this.env.localStorage.removeItem(this.storageKey("leader")); } catch {}
    }
    this.isLeader = false;
  }

  onVisibilityChange() {
    this.broadcastState();
    this.refreshLeadership();
    this.schedule(0);
  }

  hasVisiblePeer() {
    return [...this.peerStates.values()].some((peer) => peer.visible);
  }

  supportsWebLocks() {
    return typeof this.env.navigator?.locks?.request === "function";
  }

  isVisible() {
    return this.env.document?.visibilityState !== "hidden";
  }

  interval() {
    if (Date.now() < this.burstUntil) return this.options.burstInterval;
    if (Date.now() - this.lastActivity < this.options.activeWindow) return this.options.activeInterval;
    return this.options.normalInterval;
  }

  backoff(status, retryAfter = 0) {
    const fixed = { 403: 60_000, 429: 120_000, 504: 300_000 }[status] ?? 0;
    const base = Math.max(retryAfter, fixed, Math.min(300_000, this.options.burstInterval * (2 ** this.failureCount)));
    return Math.round(base + (base * this.options.jitterRatio * Math.random()));
  }

  retryAfter(value) {
    if (!value) return 0;
    if (/^\d+$/.test(value)) return Number(value) * 1000;
    const date = Date.parse(value);
    return Number.isNaN(date) ? 0 : Math.max(0, date - Date.now());
  }

  schedule(delay) {
    if (!this.started) return;
    this.env.clearTimeout(this.timer);
    this.timer = this.env.setTimeout(() => this.tick(), Math.max(0, delay));
  }

  readLease() {
    try { return parseJson(this.env.localStorage.getItem(this.storageKey("leader"))); } catch { return null; }
  }

  writeLease(lease) {
    try { this.env.localStorage.setItem(this.storageKey("leader"), JSON.stringify(lease)); } catch {}
  }

  readObject(name) {
    try { return parseJson(this.env.localStorage.getItem(this.storageKey(name)), {}) ?? {}; } catch { return {}; }
  }

  writeObject(name, value) {
    try { this.env.localStorage.setItem(this.storageKey(name), JSON.stringify(value)); } catch {}
  }

  readNumber(name, fallback) {
    try {
      const value = Number(this.env.localStorage.getItem(this.storageKey(name)));
      return Number.isFinite(value) && value >= 0 ? value : fallback;
    } catch { return fallback; }
  }

  writeNumber(name, value) {
    try { this.env.localStorage.setItem(this.storageKey(name), String(value)); } catch {}
  }

  storageKey(name) {
    return `nosocket:${this.options.namespace}:${name}`;
  }

  assertName(value, label) {
    if (typeof value !== "string" || !/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/.test(value)) {
      throw new TypeError(`${label} must be 1-128 URL-safe characters.`);
    }
  }
}

export function createNoSocket(options) {
  return new NoSocketClient(options);
}

export default NoSocketClient;
