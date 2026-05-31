# Changelog

## [0.2.0] - 2026-05-31

### Added

- Independent per-channel cursors with live bootstrap and optional retained replay.
- Retention watermarks and explicit `nosocket.resync_required` recovery.
- Async browser `tokenProvider`, expiry refresh, shared cooldowns, jitter, and visibility-aware leader handoff.
- Preferred Web Locks leader election with local-storage fallback.
- MySQL, MariaDB, and PostgreSQL integration tests, Playwright multi-tab tests, and GitHub Actions CI.
- Complete PHP and Laravel token endpoint examples plus an upgrade guide.

### Changed

- Polling is now `POST /nosocket/poll` with a JSON subscriptions map.
- Watermark schemas use an `event_id` column for MySQL and MariaDB compatibility.

## [0.1.0] - 2026-05-31

### Added

- Dependency-light PHP event log, poll service, signed channel grants, cleanup, and database limiter.
- Browser SDK with adaptive polling, leader election, cross-tab fanout, local-storage fallback, and offline cursor recovery.
- MySQL, MariaDB, and PostgreSQL schemas.
- Laravel, WordPress, Symfony, and CodeIgniter adapters.
- Tests, benchmark harness, security guidance, and integration documentation.
