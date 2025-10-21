<?php

declare(strict_types=1);

namespace MagicLink\Link;

use MagicLink\Core\Clock;
use MagicLink\Core\Claims;
use MagicLink\Core\Encoder;
use MagicLink\Core\SystemClock;
use MagicLink\Core\Token;
use MagicLink\Crypto\Cipher;
use MagicLink\Crypto\KeySet;
use MagicLink\Crypto\MacSigner;
use MagicLink\Exception\CryptoException;
use MagicLink\Exception\TokenFormatException;
use MagicLink\Store\NonceStoreInterface;

final class LinkBuilder
{
    private KeySet $keys;
    private MacSigner $signer;
    private Clock $clock;
    private ?Cipher $cipher = null;
    private NonceStoreInterface $nonceStore;

    public function __construct(KeySet $keys, NonceStoreInterface $nonceStore, Clock $clock = new SystemClock())
    {
        $this->keys = $keys;
        $this->signer = new MacSigner();
        $this->clock = $clock;
        $this->cipher = new Cipher();
        $this->nonceStore = $nonceStore;
    }

    public function createUrl(string $baseUrl, string $subject, ?CreateOptions $options = null, string $paramName = Token::PARAM_DEFAULT): string
    {
        $token = $this->createToken($subject, $options);

        $parts = parse_url($baseUrl);
        if ($parts === false) {
            throw new TokenFormatException('Base URL is malformed.');
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $query[$paramName] = $token;
        $parts['query'] = http_build_query($query);

        return self::unparseUrl($parts);
    }

    public function createToken(string $subject, ?CreateOptions $options = null): string
    {
        $options ??= new CreateOptions();
        $key = $this->keys->getForSign();
        $now = $this->clock->now();
        $exp = $now + max(1, $options->ttlSeconds);
        $jti = $options->oneTime ? self::generateNonce() : null;

        $appClaims = $options->app;
        if ($options->pathBind !== null) {
            $appClaims['bind.path'] = $options->pathBind;
        }
        if ($options->returnTo !== null) {
            $appClaims['return_to'] = $options->returnTo;
        }

        $claims = new Claims($subject, $now, $exp, $options->aud, $jti, null, $appClaims);

        $header = [
            'alg' => 'HS256',
            'kid' => $key->kid,
        ];

        $payloadData = $claims->toArray();
        $headerJson = Encoder::canonicalJsonEncode($header);
        $headerSegment = Encoder::base64UrlEncode($headerJson);

        if ($options->encryptPayload) {
            if (!Cipher::isAvailable()) {
                throw new CryptoException('Encryption requested but OpenSSL AES-GCM is unavailable.');
            }

            $header['enc'] = Cipher::ALG_A256GCM;
            $headerJson = Encoder::canonicalJsonEncode($header);
            $headerSegment = Encoder::base64UrlEncode($headerJson);
            $plaintext = Encoder::canonicalJsonEncode($payloadData);
            $cipherResult = $this->cipher->encrypt($key, $plaintext, $headerSegment);
            $payloadData = [
                'iv' => Encoder::base64UrlEncode($cipherResult['iv']),
                'tag' => Encoder::base64UrlEncode($cipherResult['tag']),
                'ct' => Encoder::base64UrlEncode($cipherResult['ciphertext']),
            ];
        }

        $payloadJson = Encoder::canonicalJsonEncode($payloadData);
        $payloadSegment = Encoder::base64UrlEncode($payloadJson);

        $token = new Token($headerSegment, $payloadSegment, '');
        $signature = $this->signer->sign($key, $token->signingInput());
        $signatureSegment = Encoder::base64UrlEncode($signature);

        return $headerSegment . '.' . $payloadSegment . '.' . $signatureSegment;
    }

    private static function generateNonce(): string
    {
        return Encoder::base64UrlEncode(random_bytes(16));
    }

    /**
     * @param array<string, mixed> $parts
     */
    private static function unparseUrl(array $parts): string
    {
        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $user = $parts['user'] ?? '';
        $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $pass = ($user || $pass) ? $pass . '@' : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . $user . $pass . $host . $port . $path . $query . $fragment;
    }
}
