import assert from "node:assert/strict";
import test from "node:test";
import { NoSocketClient } from "../../assets/js/nosocket.js";

class Storage {
  constructor() { this.values = new Map(); }
  getItem(key) { return this.values.get(key) ?? null; }
  setItem(key, value) { this.values.set(key, String(value)); }
  removeItem(key) { this.values.delete(key); }
}

function env(storage = new Storage(), overrides = {}) {
  return {
    localStorage: storage,
    navigator: { onLine: true },
    document: {
      visibilityState: "visible",
      addEventListener() {},
      removeEventListener() {},
    },
    location: { href: "https://example.test/app" },
    addEventListener() {},
    removeEventListener() {},
    setTimeout,
    clearTimeout,
    setInterval,
    clearInterval,
    ...overrides,
  };
}

test("one tab wins the storage lease fallback", () => {
  const storage = new Storage();
  const first = new NoSocketClient({ namespace: "lease" }, env(storage));
  const second = new NoSocketClient({ namespace: "lease" }, env(storage));
  first.refreshLeadership();
  second.refreshLeadership();
  assert.equal(first.isLeader, true);
  assert.equal(second.isLeader, false);
  first.stop();
  second.stop();
});

test("leader polls union subscriptions with independent cursors", () => {
  const client = new NoSocketClient({}, env());
  client.subscribe("orders");
  client.cursors.orders = 12;
  client.receive({ type: "state", source: "peer", channels: { notifications: { replay: "retained" } } });
  assert.deepEqual(client.allSubscriptions(), {
    orders: { cursor: 12, replay: "live" },
    notifications: { cursor: null, replay: "retained" },
  });
  client.stop();
});

test("retained replay wins when tabs request different bootstrap modes", () => {
  const client = new NoSocketClient({}, env());
  client.subscribe("orders");
  client.receive({ type: "state", source: "peer", channels: { orders: { replay: "retained" } } });
  assert.deepEqual(client.allSubscriptions(), { orders: { cursor: null, replay: "retained" } });
  client.stop();
});

test("leader polls immediately only when follower subscriptions change", () => {
  const client = new NoSocketClient({}, env());
  const delays = [];
  client.isLeader = true;
  client.schedule = (delay) => delays.push(delay);
  client.receive({ type: "state", source: "peer", channels: { orders: { replay: "live" } } });
  client.receive({ type: "state", source: "peer", channels: { orders: { replay: "live" } } });
  client.receive({ type: "state", source: "peer", channels: { notifications: { replay: "live" }, orders: { replay: "live" } } });
  assert.deepEqual(delays, [0, 0]);
  client.stop();
});

test("overlapping timer ticks send only one poll", async () => {
  let resolvePoll;
  let polls = 0;
  const client = new NoSocketClient({ token: "opaque" }, env());
  client.started = true;
  client.subscribe("orders");
  client.refreshLeadership = () => { client.isLeader = true; };
  client.schedule = () => {};
  client.poll = () => {
    polls += 1;
    return new Promise((resolve) => { resolvePoll = resolve; });
  };
  const first = client.tick();
  const second = client.tick();
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(polls, 1);
  resolvePoll({ events: [], cursors: { orders: 0 }, has_more: false, resync_required: [] });
  await Promise.all([first, second]);
  client.stop();
});

test("tokenProvider receives channel union and refresh reason", async () => {
  const calls = [];
  const client = new NoSocketClient({
    tokenProvider: async (request) => {
      calls.push(request);
      return { token: "opaque", expiresAt: Date.now() + 60_000 };
    },
  }, env());
  await client.ensureToken(["orders", "notifications"], "poll");
  await client.ensureToken(["notifications", "orders"], "poll");
  await client.ensureToken(["orders"], "forbidden", true);
  assert.deepEqual(calls, [
    { channels: ["notifications", "orders"], reason: "poll" },
    { channels: ["orders"], reason: "forbidden" },
  ]);
  client.stop();
});

test("resync pauses channel until explicitly resumed", () => {
  const client = new NoSocketClient({}, env());
  client.subscribe("orders");
  client.cursors.orders = 4;
  let required = "";
  client.on("nosocket.resync_required", ({ channel }) => { required = channel; });
  client.requireResync("orders");
  assert.equal(required, "orders");
  assert.deepEqual(client.allSubscriptions(), {});
  client.resync("orders");
  assert.deepEqual(client.allSubscriptions(), { orders: { cursor: null, replay: "live" } });
  client.stop();
});

test("successful onResync callback resumes channel automatically", async () => {
  const client = new NoSocketClient({ onResync: async () => {} }, env());
  client.subscribe("orders");
  client.cursors.orders = 4;
  client.requireResync("orders");
  await Promise.resolve();
  await Promise.resolve();
  assert.deepEqual(client.allSubscriptions(), { orders: { cursor: null, replay: "live" } });
  client.stop();
});

test("events dispatch once and update only their channel cursor", () => {
  const client = new NoSocketClient({}, env());
  let value = 0;
  client.on("order.created", (payload) => { value = payload.id; });
  client.accept([{ id: 4, channel: "orders", event: "order.created", payload: { id: 123 } }]);
  client.accept([{ id: 4, channel: "orders", event: "order.created", payload: { id: 999 } }]);
  assert.equal(value, 123);
  assert.deepEqual(client.cursors, { orders: 4 });
  client.stop();
});

test("backoff uses Retry-After and shared cooldown", () => {
  const storage = new Storage();
  const client = new NoSocketClient({ jitterRatio: 0 }, env(storage));
  client.failureCount = 1;
  assert.equal(client.backoff(403), 60_000);
  assert.equal(client.backoff(429, 180_000), 180_000);
  assert.equal(client.retryAfter("120"), 120_000);
  client.receive({ type: "cooldown", source: "peer", until: Date.now() + 10_000 });
  assert.ok(client.readNumber("cooldown", 0) > Date.now());
  client.stop();
});

test("hidden leader releases lease when a visible peer exists", () => {
  const storage = new Storage();
  const hiddenEnv = env(storage);
  hiddenEnv.document.visibilityState = "hidden";
  const client = new NoSocketClient({}, hiddenEnv);
  client.refreshLeadership();
  assert.equal(client.isLeader, true);
  client.peerStates.set("visible-peer", { visible: true, seenAt: Date.now(), channels: {} });
  client.refreshLeadership();
  assert.equal(client.isLeader, false);
  assert.equal(client.readLease(), null);
  client.stop();
});

test("Web Locks API is preferred when available", () => {
  let requests = 0;
  const testEnv = env();
  testEnv.navigator.locks = {
    request: async (_key, _options, callback) => {
      requests += 1;
      return callback({});
    },
  };
  const client = new NoSocketClient({}, testEnv);
  client.started = true;
  client.refreshLeadership();
  assert.equal(requests, 1);
  assert.equal(client.isLeader, true);
  client.stop();
});

test("poll posts JSON subscriptions and reads Retry-After failures", async () => {
  let request;
  const client = new NoSocketClient({ token: "opaque" }, env(undefined, {
    fetch: async (url, options) => {
      request = { url: String(url), options };
      return { ok: false, status: 429, headers: { get: () => "15" } };
    },
  }));
  await assert.rejects(
    () => client.poll({ orders: { cursor: 1, replay: "live" } }),
    (error) => error.status === 429 && error.retryAfter === 15_000
  );
  assert.equal(request.options.method, "POST");
  assert.equal(request.options.body, JSON.stringify({ subscriptions: { orders: { cursor: 1, replay: "live" } } }));
  client.stop();
});
