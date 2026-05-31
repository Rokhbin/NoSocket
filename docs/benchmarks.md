# Benchmarks

Run:

```bash
php benchmarks/run.php
```

The script reports a local HMAC signing and verification microbenchmark and a deterministic request model. On this repository's development container, the request model for 100 users with 10 tabs each at the default 30-second interval is:

| Design | Requests per minute | Reduction |
| --- | ---: | ---: |
| Traditional per-tab polling | 2,000 | - |
| NoSocket leader polling | 200 | 90% |

The included PHP 8.5 Linux development container measured about 198,000-241,000 combined sign-or-verify operations per second across local runs on May 31, 2026. This microbenchmark is environment-specific and is not a hosted-load claim. Before production, test against the intended shared host with realistic payload sizes, indexes, TTL, and active-user patterns.

## Protocol 0.2 Comparison

Per-channel cursors and retention watermarks add correctness metadata without adding idle HTTP requests:

| Version | 100 users x 10 tabs | Idle requests per minute | Retention gap detection |
| --- | ---: | ---: | --- |
| `0.1` global cursor | 1,000 tabs | 200 | No |
| `0.2` channel cursors | 1,000 tabs | 200 | Yes |
