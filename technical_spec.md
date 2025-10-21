# Magic Link PHP Library — Technical Specification (Dependency-Free)

## 1) Overview

A small, framework-agnostic PHP library for generating and validating **magic links** (a.k.a. passwordless, time-bound, signed URLs). The library is **external-dependency-free** (no Composer packages), compatible with vanilla PHP runtimes, and ships as a single namespace with optional interfaces for storage and framework integration.

Primary use cases:

* Email/SMS “Sign in with a link”
* Email verification links
* One-click actions (unsubscribe, confirm, reset, deep links)
* Privileged but time-boxed admin flows

---

## 2) Goals & Non-Goals

**Goals**

* Stateless signed tokens with strong integrity (HMAC) and optional confidentiality (OpenSSL AES when available).
* Clear, minimal public API with sensible defaults.
* No Composer/package dependencies; only standard PHP functions/extensions.
* Works with any transport (email, SMS, push) and any framework.
* Defenses against replay, tampering, link harvesting, and token leakage.
* Deterministic token format and stable, testable behavior.

**Non-Goals**

* User directory / email delivery / templating.
* Database schema or migrations (provide interfaces only).
* Multi-factor auth workflow orchestration (can be layered on top).

---

## 3) Terminology

* **Token**: The compact, signed payload embedded in a URL query param.
* **Claim**: A field inside the token payload (e.g., `sub`, `aud`, `exp`).
* **One-time**: Token invalid after first successful verification.
* **Nonce**: Random unique string bound to a token to prevent replay.

---

## 4) Requirements

**Functional**

* Generate a link: `string createLink(Claims, Options)`.
* Verify a link: `VerificationResult verifyFromRequest(...)` and `TokenVerification verifyToken(string $token, VerifyOptions $opts)`.
* Support one-time and multi-use tokens.
* Ability to bind to audience (`aud`), route path, and optional context (e.g., `ua` hash).
* Provide cryptographic key rotation (multiple active keys).

**Security**

* Integrity: HMAC-SHA256 or stronger via `hash_hmac`.
* Entropy: `random_bytes()` for nonce/key IDs, fallback to `openssl_random_pseudo_bytes()`.
* Constant-time comparisons with `hash_equals()`.
* Time-based validity (`iat`, `nbf`, `exp`) with configurable clock skew.
* Optional confidentiality: AES-256-GCM via `openssl_*` if available (graceful fallback to signed-only).
* Replay protection via nonce store interface.
* Canonicalization rules for signing to avoid ambiguity.

**Compatibility**

* PHP **8.0+** (typed properties, union types, strict typing).
* No Composer packages; uses only standard PHP extensions (`hash`, `openssl` optional).
* Works without sessions.

**Performance**

* Encoding/decoding O(payload).
* No network calls; storage adapters are user-provided if needed.

---

## 5) Architecture & Namespaces

```
MagicLink\
  Crypto\
    Key          // immutable key material
    KeySet       // rotation support
    MacSigner    // HMAC signer/verifier
    Cipher       // optional AES-GCM encrypt/decrypt
  Core\
    Claims       // value object with validation
    Token        // header + claims + signature
    Encoder      // Base64url + canonical JSON
    Clock        // interface + SystemClock
  Link\
    LinkBuilder  // builds URLs with token
    Verifier     // orchestrates decode, checks, replay, bindings
  Store\
    NonceStoreInterface      // for one-time tokens
    MemoryNonceStore         // in-memory (dev/test)
    BlackholeNonceStore      // no-op for multi-use
  Http\
    RequestAdapterInterface  // small shim to extract path, query, ua, ip
  Exception\
    CryptoException
    TokenFormatException
    VerificationException
    ClockSkewException
```

---

## 6) Token Format

A compact, URL-safe, Base64url-encoded structure:

```
token := base64url(header) + "." + base64url(payload) + "." + base64url(signature)
```

**Header (JSON)**

* `alg`: `"HS256"` (HMAC-SHA256). Reserved for future algorithms.
* `kid`: key identifier (string, 8–32 bytes base64url).
* `enc`: optional `"A256GCM"` when payload is encrypted.

**Payload (JSON)**

* Registered claims:

  * `sub` (string): subject — required (e.g., user ID or email hash).
  * `aud` (string): audience — optional (e.g., `"signin"`, `"verify_email"`).
  * `iat` (int): issued at (unix seconds) — required.
  * `nbf` (int): not before — optional.
  * `exp` (int): expires at — required.
  * `jti` (string): nonce/unique ID — required for one-time tokens.
* App claims (namespaced recommended, e.g., `app.role`, `app.return_to`).
* **If encrypted**: `payload` holds ciphertext + `iv` + `tag` (GCM).

**Canonicalization**

* JSON encoding with:

  * UTF-8
  * Sorted keys
  * No insignificant whitespace
  * Integers as numbers, booleans as true/false, strings exact
* Base64url without padding.

---

## 7) Cryptography

**Signing**

* Algorithm: HMAC-SHA256 via `hash_hmac('sha256', data, key, true)`.
* Input to HMAC: `base64url(header) + "." + base64url(payload)`.
* Constant-time verify with `hash_equals()`.

**Encryption (Optional)**

* If `openssl` is available: AES-256-GCM (`openssl_encrypt`, `openssl_decrypt`).
* Random 12-byte IV per token.
* Store `iv` and `tag` in payload (base64url).
* If `openssl` is unavailable or disabled, library transparently falls back to signed-only tokens; confidentiality not guaranteed.

**Key Management**

* `Crypto\Key`: `{kid, secret, createdAt, expiresAt?}`
* `Crypto\KeySet`: holds active + retired keys (verify can use retired; sign uses newest active).
* Keys are caller-supplied; library never persists keys.
* Recommended key length: 32 bytes (256-bit).
* Rotation: add new key with new `kid`, mark old as verifying-only, then retire.

---

## 8) Claim Binding & Context

Optional bindings to reduce token portability:

* **Audience** (`aud`): separates flows (e.g., `"signin"` vs `"unsubscribe"`).
* **Route path binding**: `VerifyOptions` may include `expectedPath` (exact or prefix) to compare against the current request path.
* **Origin/Host binding**: compare `expectedHost` if desired.
* **User-Agent hash**: store `uah = base64url(sha256(UA))` as an app claim to reduce token reuse across devices. Disabled by default to avoid breakage.
* **IP netmask binding**: optional, not default (NAT/proxies may change IPs).
* **Return URL**: app claim `app.return_to` whitelisted via allowlist to mitigate open redirects.

---

## 9) Replay & One-Time Use

* For one-time tokens, require `jti` (nonce). On successful verify, Verifier calls `NonceStoreInterface::consume(string $jti, int $exp)`. If already consumed, reject.
* Provide `MemoryNonceStore` for tests/dev only.
* Production users implement `NonceStoreInterface` (e.g., Redis with TTL to `exp`).
* Multi-use tokens may omit `jti` and use shorter TTLs.

---

## 10) Public API (Draft)

```php
declare(strict_types=1);

namespace MagicLink\Core;

final class Claims {
    public string $sub;
    public ?string $aud = null;
    public int $iat;
    public ?int $nbf = null;
    public int $exp;
    public ?string $jti = null; // required for one-time
    /** @var array<string, scalar|array|int|bool|null> */
    public array $app = [];      // namespaced app claims
}

namespace MagicLink\Crypto;

final class Key {
    public function __construct(
        public string $kid,    // base64url id
        public string $secret, // raw bytes
        public int $createdAt,
        public ?int $expiresAt = null
    ) {}
}

final class KeySet {
    /** @param Key[] $keys */
    public function __construct(array $keys = []);
    public function add(Key $key): void;
    public function getForSign(): Key;         // newest active
    public function find(string $kid): ?Key;   // for verify
    /** @return Key[] */
    public function all(): array;
}

namespace MagicLink\Core;

interface Clock {
    public function now(): int; // unix seconds
}
final class SystemClock implements Clock {
    public function now(): int;
}

namespace MagicLink\Store;

interface NonceStoreInterface {
    /** Returns true on first consume, false if already consumed */
    public function consume(string $jti, int $expiresAt): bool;
}

final class MemoryNonceStore implements NonceStoreInterface {
    public function __construct();
    public function consume(string $jti, int $expiresAt): bool;
}

final class BlackholeNonceStore implements NonceStoreInterface {
    public function consume(string $jti, int $expiresAt): bool;
}

namespace MagicLink\Link;

final class CreateOptions {
    public ?string $aud = null;
    public int $ttlSeconds = 900;            // default 15 min
    public bool $oneTime = true;
    public bool $encryptPayload = false;     // requires openssl
    public ?string $pathBind = null;         // e.g., "/auth/callback"
    public ?string $returnTo = null;         // optional, allowlisted by app
    /** @var array<string, mixed> */
    public array $app = [];
}

final class VerifyOptions {
    public ?string $expectedAud = null;
    public ?string $expectedPath = null;     // exact or prefix mode (see policy)
    public ?string $expectedHost = null;
    public bool $requireOneTime = false;
    public int $maxClockSkew = 120;          // seconds
    public bool $enforceUaHash = false;
    public ?callable $returnToAllowlist = null; // fn(string $url): bool
}

final class LinkBuilder {
    public function __construct(
        Crypto\KeySet $keys,
        Store\NonceStoreInterface $nonceStore,
        Core\Clock $clock = new Core\SystemClock()
    );

    /** Creates a signed (and optionally encrypted) token and returns full URL */
    public function createUrl(
        string $baseUrl,   // e.g., "https://site.tld/auth/callback"
        string $subject,   // sub
        ?CreateOptions $options = null,
        string $paramName = "ml" // query parameter key
    ): string;

    /** Creates a raw token for custom embedding */
    public function createToken(
        string $subject, ?CreateOptions $options = null
    ): string;
}

namespace MagicLink\Link;

final class TokenVerification {
    public bool $ok;
    public ?string $reason;      // machine-readable code
    public ?Core\Claims $claims; // present when ok
}

final class Verifier {
    public function __construct(
        Crypto\KeySet $keys,
        Store\NonceStoreInterface $nonceStore,
        Core\Clock $clock = new Core\SystemClock()
    );

    /** Verify a raw token string */
    public function verifyToken(
        string $token,
        ?VerifyOptions $options = null,
        ?string $userAgent = null,
        ?string $path = null,
        ?string $host = null
    ): TokenVerification;

    /** Helper to extract token from URL and verify */
    public function verifyFromRequest(
        string $tokenOrUrl,
        ?VerifyOptions $options = null,
        ?string $userAgent = null
    ): TokenVerification;
}
```

**Error/Reason Codes (non-exceptional path)**

* `ok=true` or one of:

  * `malformed_header`, `malformed_payload`, `malformed_token`
  * `unknown_kid`, `signature_mismatch`
  * `token_expired`, `token_early` (nbf), `clock_skew`
  * `aud_mismatch`, `path_mismatch`, `host_mismatch`
  * `ua_mismatch`, `replayed`, `one_time_required`
  * `return_to_denied`
  * `encryption_unavailable`, `decrypt_failed`

Exceptions reserved for programming/crypto errors (e.g., bad key size).

---

## 11) Encoders & Canonicalization

* **Base64url**: implement encode/decode without padding (`=`). Replace `+`→`-`, `/`→`_`.
* **Canonical JSON**:

  * Implement deterministic encoder:

    * Recursively sort object keys
    * Encode UTF-8
    * Use `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE`
  * Reject NaN/INF values.

---

## 12) Verification Pipeline (Step-By-Step)

1. **Parse**: split token into three segments; base64url-decode header & payload.
2. **Validate header**: `alg == 'HS256'`, known `kid`.
3. **(If enc)**: decrypt payload JSON using AES-GCM (`iv`, `tag`).
4. **Re-encode canonical header+payload**: verify HMAC using key by `kid`.
5. **Timestamps**: ensure `iat <= now + skew`, `nbf` (if set) `<= now + skew`, `exp >= now - skew`.
6. **Audience**: if `expectedAud` set, equal to `aud`.
7. **Bindings**:

   * Path: if `expectedPath` set, compare; prefix mode allowed via policy (see §14).
   * Host: if set, compare against request host.
   * UA: if enforced, compute `uah` and compare to claim.
8. **One-time**:

   * If `requireOneTime` or payload has `jti`, call `nonceStore->consume($jti, $exp)`. Reject if false.
9. **Return URL allowlist**:

   * If `app.return_to` present, call `returnToAllowlist($url)`. Reject if false.
10. **Result**: return `TokenVerification { ok, reason, claims }`.

---

## 13) Key Rotation Procedure

1. Generate new 32-byte secret with `random_bytes(32)`.
2. Assign new `kid` (16 bytes base64url recommended).
3. Add to `KeySet` as signing key.
4. Keep previous key for verification for N days.
5. After traffic has migrated (max token TTL), remove old key.

---

## 14) Policies & Defaults

* Default TTL: **15 minutes**.
* Default audience: `null` (caller should set).
* Default one-time: **true**.
* Default path binding: `null`. If set, **exact** match by default.
* Optional prefix match policy: if `expectedPath` ends with `*`, treat as prefix.
* Clock skew: **±120s**.
* Encryption: off by default; enable if confidentiality is required and `openssl` is available.

---

## 15) Storage Adapter Guidance (Nonce Store)

`NonceStoreInterface::consume(string $jti, int $exp): bool`

* **Redis example (recommended)**:

  * `SETNX jti:<jti> 1` then `EXPIREAT` with `$exp`.
  * Return true if `SETNX` succeeded, false otherwise.
* **SQL example**:

  * `INSERT ... ON CONFLICT DO NOTHING` + `expires_at` column; periodic cleanup.
* **MemoryNonceStore**: for tests only (process-local).

---

## 16) HTTP Request Integration

Provide `RequestAdapterInterface` only if needed by the host app. The library’s core should accept primitives:

* Token string or URL
* Path string
* Host string
* User-Agent string

This avoids framework coupling.

---

## 17) Security Considerations & Threat Model

* **Tampering**: HMAC signature over canonical header+payload.
* **Replay**: `jti` + `NonceStore` for one-time tokens; short TTL for multi-use.
* **Harvesting**: Keep tokens short-lived; prefer one-time; avoid embedding sensitive PII unless encrypting.
* **Leakage in logs**: Document best practices—redact query params containing `ml` in logs.
* **Phishing / open redirects**: Allowlist for `return_to`; bind audience & path.
* **CSRF confusions**: Treat magic-link as authentication event; still require CSRF token for subsequent state-changing actions.
* **UA/IP binding trade-offs**: Optional; may cause false negatives on mobile networks.
* **Key exposure**: Keep keys out of code; inject via env/secret store; rotate periodically.

---

## 18) Error Handling & Observability

* **Non-exceptional** verification failures return `TokenVerification` with `ok=false` and reason code.
* **Exceptions** thrown only for programmer errors (invalid key size, bad IV length, JSON canonicalization failure).
* Provide optional logger hook (callable) in `Verifier` to emit structured events:

  * `token_verified`, `token_rejected`, `replay_detected`, `signature_mismatch`, etc.
* Never log raw tokens; log truncated or hashed (`sha256(token)`).

---

## 19) Configuration & Initialization Example

```php
use MagicLink\Crypto\{Key, KeySet};
use MagicLink\Link\{LinkBuilder, Verifier, CreateOptions, VerifyOptions};
use MagicLink\Store\{MemoryNonceStore};

$keys = new KeySet([
    new Key(kid: 'Ab3X9QpL', secret: random_bytes(32), createdAt: time()),
]);

$nonceStore = new MemoryNonceStore(); // replace with Redis adapter in prod

$linkBuilder = new LinkBuilder($keys, $nonceStore);
$verifier    = new Verifier($keys, $nonceStore);

// Create
$opts = new CreateOptions();
$opts->aud = 'signin';
$opts->ttlSeconds = 900;
$opts->oneTime = true;
$opts->pathBind = '/auth/callback';

$url = $linkBuilder->createUrl('https://app.example.com/auth/callback', 'user-123', $opts);

// Verify
$vopts = new VerifyOptions();
$vopts->expectedAud  = 'signin';
$vopts->expectedPath = '/auth/callback';
$vopts->requireOneTime = true;

$res = $verifier->verifyFromRequest($url, $vopts, $_SERVER['HTTP_USER_AGENT'] ?? null);
if ($res->ok) {
    $userId = $res->claims->sub;
    // proceed
}
```

---

## 20) Base64url & Canonical JSON (Reference Behaviors)

* **Base64url encode**:

  * `rtrim(strtr(base64_encode($bin), '+/', '-_'), '=')`
* **Base64url decode**:

  * Add padding back to multiple of 4; reverse `-_/+/`.
* **JSON canonicalization**:

  * Recursively `ksort()` associative arrays; re-encode with `json_encode` flags.
  * Validate that decoded form matches encoded (round-trip).

---

## 21) Testing Strategy

**Unit**

* Base64url encode/decode vectors
* Canonical JSON ordering & round-trips
* HMAC sign/verify vectors (fixed key, known payload)
* AES-GCM encrypt/decrypt (skip if openssl missing; otherwise test vectors)
* Claim validation (iat/nbf/exp/clock skew)
* Path/host/UA bindings
* Nonce store idempotency

**Property-based**

* Random claims → encode/decode → verify invariants

**Time-based**

* Fake clock (inject `Clock`) to test `nbf/exp/skew`

**Interoperability**

* Ensure tokens survive URL encoding (query param `ml`)

**Fuzzing**

* Corrupt segments; random junk; padding/whitespace

---

## 22) Performance Considerations

* Token length target: ≤ 512 bytes typical (no encryption), ≤ 768 with AES-GCM.
* Avoid repeated JSON decode/encode where possible (cache canonical form during sign).
* Use `hash_equals()` to compare signatures.
* MemoryNonceStore is O(1) average; prod store must be similarly efficient.

---

## 23) Versioning & Compatibility

* **Semantic Versioning**:

  * `1.x`: Stable token format and APIs.
  * Any change to token format increments **major**.
* Public constants:

  * `Token::PARAM_DEFAULT = 'ml'`
  * `Algorithms::HS256`, `Ciphers::A256GCM`

---

## 24) Documentation Notes (to ship with code)

* Quickstart (as above)
* Key rotation guide
* Security checklist
* Adapters cookbook (Redis, PDO)
* Troubleshooting (common reason codes)

---

## 25) Implementation Checklist

* [ ] Base64url encoder/decoder (no padding)
* [ ] Canonical JSON encoder/decoder (sorted keys)
* [ ] Header/payload models with validation
* [ ] HMAC signer/verifier (HS256)
* [ ] Optional AES-GCM encrypt/decrypt (feature-detect `openssl`)
* [ ] `Key`/`KeySet` with signing vs verifying logic
* [ ] `LinkBuilder` + URL assembly (`ml` param)
* [ ] `Verifier` pipeline with reason codes
* [ ] `NonceStoreInterface` + Memory & Blackhole implementations
* [ ] `Clock` interface + `SystemClock`
* [ ] Exceptions and safe logging helpers
* [ ] Comprehensive unit tests
* [ ] PHPDoc + type hints throughout
* [ ] Strict types and `declare(strict_types=1);`

---

## 26) Security Checklist (for adopters)

* Generate 256-bit keys with `random_bytes(32)`.
* Store keys in a secrets manager; rotate regularly.
* Prefer **one-time** tokens; keep TTLs short (≤15 min).
* Bind to audience and callback path.
* Allowlist `return_to` domains/paths.
* Redact `ml` in logs and analytics.
* Enforce HTTPS in production; set HSTS.
* Consider enabling AES-GCM when payload contains sensitive data.
* Monitor `replayed`/`signature_mismatch` events.

---

## 27) License & Attribution

* Library source: MIT (recommendation).
* No third-party code; built-ins only.

---

### Appendix A — Reason Codes (Full List)

`ok`, `malformed_token`, `malformed_header`, `malformed_payload`, `unknown_kid`, `signature_mismatch`, `encryption_unavailable`, `decrypt_failed`, `token_expired`, `token_early`, `clock_skew`, `aud_mismatch`, `path_mismatch`, `host_mismatch`, `ua_mismatch`, `replayed`, `one_time_required`, `return_to_denied`.

---

**End of Specification**
