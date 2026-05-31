# Security Policy

## Reporting

Do not open public issues for vulnerabilities. Email `security@nosocket.dev` with reproduction details and affected versions. Maintainers aim to acknowledge reports within seven days.

## Model

- Poll grants are HMAC-signed bearer tokens with scoped channels, subject, issued-at timestamp, expiry, and nonce.
- Grants are deliberately reusable during their short lifetime because polling repeats. The nonce gives tokens unique identity for application-level revocation or auditing; the core does not persist a denylist.
- A stolen unexpired bearer token can be replayed. Require HTTPS, use short TTLs, scope channels narrowly, and issue tokens only through authenticated CSRF-protected routes.
- Use browser `tokenProvider` in production so leader tabs refresh grants when the union of subscribed channels changes.
- Use a private SDK namespace per authenticated user, such as `shop:user-42`, so local cursor state is not reused across sessions.
- The built-in database limiter reduces abuse but does not replace edge rate limiting where that is available.
- Treat received events as untrusted input in browser code. Validate payloads before emit and escape values before HTML rendering.
