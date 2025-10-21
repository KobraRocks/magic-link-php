# magic-link-php

Magic Link PHP is a lightweight, dependency-free library for creating and verifying passwordless "magic link" URLs. It provides
cryptographically signed (and optionally encrypted) tokens that you can embed into email or SMS links to authenticate users or
grant time-bound access to privileged actions.

## Purpose

* Generate signed tokens that encode the subject, audience, expiration, and custom claims for your application.
* Attach those tokens to URLs that can be sent to users for passwordless sign-in, email verification, or one-click actions.
* Verify incoming tokens with replay protection, audience/path bindings, optional user-agent pinning, and key rotation support.

## Installation

The library ships without external Composer dependencies. You can require it directly from your VCS repository or copy the
`src/` tree into your project. When using Composer, add an entry similar to the following to your `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/your-org/magic-link-php"
        }
    ],
    "require": {
        "your-org/magic-link-php": "^1.0"
    }
}
```

Run `composer dump-autoload` after installing so that classes under the `MagicLink\\` namespace are autoloaded.

## Usage

### Generate a magic link with `LinkBuilder`

Use `MagicLink\Link\LinkBuilder` to create a complete URL that contains a signed token. The builder needs a key set (for signing)
and a nonce store (for one-time token replay protection).

```php
<?php

use MagicLink\Crypto\Key;
use MagicLink\Crypto\KeySet;
use MagicLink\Link\CreateOptions;
use MagicLink\Link\LinkBuilder;
use MagicLink\Store\MemoryNonceStore;

// 1. Configure your signing keys (supports rotation).
$keySet = new KeySet([
    new Key('primary', random_bytes(32)),
]);

// 2. Choose a nonce store. MemoryNonceStore is suitable for tests; swap with Redis/DB in production.
$nonceStore = new MemoryNonceStore();

// 3. Build a link valid for 15 minutes that redirects back to a specific path.
$options = new CreateOptions();
$options->ttlSeconds = 900;           // 15 minutes
$options->aud = 'signin';             // Audience binding
$options->returnTo = 'https://app.example.com/dashboard';
$options->oneTime = true;             // Mark the token for single use

$linkBuilder = new LinkBuilder($keySet, $nonceStore);
$magicLink = $linkBuilder->createUrl(
    'https://auth.example.com/callback',
    $subject = 'user-123',
    $options,
);

// $magicLink now contains https://auth.example.com/callback?ml=...signed token...
```

If you need just the compact token (to embed into a custom URL), call `createToken()` instead of `createUrl()`:

```php
$token = $linkBuilder->createToken('user-123', $options);
```

### Verify a token with `Verifier::verifyToken`

`MagicLink\Link\Verifier` checks the signature, expiration, bindings, and optional replay protection for a raw token. Provide the
same key set and nonce store that were used for generation.

```php
<?php

use MagicLink\Crypto\Key;
use MagicLink\Crypto\KeySet;
use MagicLink\Link\Verifier;
use MagicLink\Link\VerifyOptions;
use MagicLink\Store\MemoryNonceStore;

$keySet = new KeySet([
    new Key('primary', $signingSecret),
]);

$nonceStore = new MemoryNonceStore();
$verifier = new Verifier($keySet, $nonceStore);

$options = new VerifyOptions();
$options->expectedAud = 'signin';
$options->requireOneTime = true;

$result = $verifier->verifyToken($token, $options, $userAgent, $path, $host);

if ($result->isSuccess()) {
    $claims = $result->getClaims();
    $userId = $claims->sub; // "user-123"
} else {
    error_log('Magic link failed: ' . $result->getReason());
}
```

Arguments such as `$userAgent`, `$path`, and `$host` allow you to enforce user-agent hashes and URL bindings when combined with
`VerifyOptions`.

### Verify directly from an incoming URL with `Verifier::verifyFromRequest`

When you receive a callback request that still contains the magic link, `verifyFromRequest()` extracts the token from the default
`ml` query parameter and automatically checks path and host bindings.

```php
<?php

use MagicLink\Crypto\Key;
use MagicLink\Crypto\KeySet;
use MagicLink\Link\Verifier;
use MagicLink\Store\MemoryNonceStore;

$keySet = new KeySet([
    new Key('primary', $signingSecret),
]);

$verifier = new Verifier($keySet, new MemoryNonceStore());

// Example URL copied from an email
$url = 'https://auth.example.com/callback?ml=' . urlencode($token);
$result = $verifier->verifyFromRequest($url);

if ($result->isSuccess()) {
    // Proceed with sign-in flow
} else {
    http_response_code(400);
    echo 'Invalid or expired link: ' . $result->getReason();
}
```

`verifyFromRequest()` also accepts a `VerifyOptions` instance and a user agent string if you need additional enforcement.

## Nonce stores

The library includes two nonce store implementations under `MagicLink\Store`:

* `MemoryNonceStore` — in-memory, suitable for development or tests.
* `BlackholeNonceStore` — no-op implementation for multi-use tokens where replay protection is unnecessary.

In production you should provide your own implementation of `NonceStoreInterface` that persists consumed nonces in Redis, a
database, or another centralized store.

## Testing

The repository ships with a PHP test runner:

```bash
php tests/run.php
```

Running the suite is recommended after integrating the library or making modifications.

## License

MIT
