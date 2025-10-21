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

final class Verifier
{
    private const REASON_MALFORMED_TOKEN = 'malformed_token';
    private const REASON_MALFORMED_HEADER = 'malformed_header';
    private const REASON_MALFORMED_PAYLOAD = 'malformed_payload';
    private const REASON_UNKNOWN_KID = 'unknown_kid';
    private const REASON_SIGNATURE_MISMATCH = 'signature_mismatch';
    private const REASON_ENCRYPTION_UNAVAILABLE = 'encryption_unavailable';
    private const REASON_DECRYPT_FAILED = 'decrypt_failed';
    private const REASON_TOKEN_EXPIRED = 'token_expired';
    private const REASON_TOKEN_EARLY = 'token_early';
    private const REASON_CLOCK_SKEW = 'clock_skew';
    private const REASON_AUD_MISMATCH = 'aud_mismatch';
    private const REASON_PATH_MISMATCH = 'path_mismatch';
    private const REASON_HOST_MISMATCH = 'host_mismatch';
    private const REASON_UA_MISMATCH = 'ua_mismatch';
    private const REASON_REPLAYED = 'replayed';
    private const REASON_ONE_TIME_REQUIRED = 'one_time_required';
    private const REASON_RETURN_TO_DENIED = 'return_to_denied';

    private KeySet $keys;
    private NonceStoreInterface $nonceStore;
    private Clock $clock;
    private MacSigner $signer;
    private ?Cipher $cipher = null;

    public function __construct(KeySet $keys, NonceStoreInterface $nonceStore, Clock $clock = new SystemClock())
    {
        $this->keys = $keys;
        $this->nonceStore = $nonceStore;
        $this->clock = $clock;
        $this->signer = new MacSigner();
        $this->cipher = new Cipher();
    }

    public function verifyToken(string $tokenString, ?VerifyOptions $options = null, ?string $userAgent = null, ?string $path = null, ?string $host = null): TokenVerification
    {
        $options ??= new VerifyOptions();

        $token = Token::fromString($tokenString);
        if ($token === null) {
            return TokenVerification::failure(self::REASON_MALFORMED_TOKEN);
        }

        try {
            $headerJson = Encoder::base64UrlDecode($token->headerSegment);
        } catch (TokenFormatException $exception) {
            return TokenVerification::failure(self::REASON_MALFORMED_TOKEN);
        }

        try {
            $payloadJson = Encoder::base64UrlDecode($token->payloadSegment);
        } catch (TokenFormatException $exception) {
            return TokenVerification::failure(self::REASON_MALFORMED_TOKEN);
        }

        try {
            $signature = Encoder::base64UrlDecode($token->signatureSegment);
        } catch (TokenFormatException $exception) {
            return TokenVerification::failure(self::REASON_MALFORMED_TOKEN);
        }

        try {
            $header = Encoder::jsonDecodeObject($headerJson);
        } catch (TokenFormatException $exception) {
            return TokenVerification::failure(self::REASON_MALFORMED_HEADER);
        }

        if (($header['alg'] ?? null) !== 'HS256') {
            return TokenVerification::failure(self::REASON_MALFORMED_HEADER);
        }

        if (!isset($header['kid']) || !is_string($header['kid']) || $header['kid'] === '') {
            return TokenVerification::failure(self::REASON_MALFORMED_HEADER);
        }

        $key = $this->keys->find($header['kid']);
        if ($key === null) {
            return TokenVerification::failure(self::REASON_UNKNOWN_KID);
        }

        if (!$this->signer->verify($key, $token->signingInput(), $signature)) {
            return TokenVerification::failure(self::REASON_SIGNATURE_MISMATCH);
        }

        $payloadData = null;
        $isEncrypted = isset($header['enc']);
        if ($isEncrypted) {
            if ($header['enc'] !== Cipher::ALG_A256GCM) {
                return TokenVerification::failure(self::REASON_MALFORMED_HEADER);
            }

            if (!Cipher::isAvailable()) {
                return TokenVerification::failure(self::REASON_ENCRYPTION_UNAVAILABLE);
            }

            try {
                $encryptedPayload = Encoder::jsonDecodeObject($payloadJson);
            } catch (TokenFormatException $exception) {
                return TokenVerification::failure(self::REASON_MALFORMED_PAYLOAD);
            }

            if (!isset($encryptedPayload['iv'], $encryptedPayload['tag'], $encryptedPayload['ct'])) {
                return TokenVerification::failure(self::REASON_MALFORMED_PAYLOAD);
            }

            try {
                if (!is_string($encryptedPayload['iv']) || !is_string($encryptedPayload['tag']) || !is_string($encryptedPayload['ct'])) {
                    return TokenVerification::failure(self::REASON_MALFORMED_PAYLOAD);
                }

                $iv = Encoder::base64UrlDecode($encryptedPayload['iv']);
                $tag = Encoder::base64UrlDecode($encryptedPayload['tag']);
                $ciphertext = Encoder::base64UrlDecode($encryptedPayload['ct']);
            } catch (TokenFormatException $exception) {
                return TokenVerification::failure(self::REASON_MALFORMED_PAYLOAD);
            }

            try {
                $plaintext = $this->cipher->decrypt($key, $ciphertext, $iv, $tag, $token->headerSegment);
            } catch (CryptoException $exception) {
                return TokenVerification::failure(self::REASON_DECRYPT_FAILED);
            }

            try {
                $payloadData = Encoder::jsonDecodeObject($plaintext);
            } catch (TokenFormatException $exception) {
                return TokenVerification::failure(self::REASON_MALFORMED_PAYLOAD);
            }
        } else {
            try {
                $payloadData = Encoder::jsonDecodeObject($payloadJson);
            } catch (TokenFormatException $exception) {
                return TokenVerification::failure(self::REASON_MALFORMED_PAYLOAD);
            }
        }

        try {
            $claims = Claims::fromArray($payloadData);
        } catch (TokenFormatException $exception) {
            return TokenVerification::failure(self::REASON_MALFORMED_PAYLOAD);
        }

        $now = $this->clock->now();
        $skew = max(0, $options->maxClockSkew);

        if ($claims->iat > $now + $skew) {
            return TokenVerification::failure(self::REASON_CLOCK_SKEW);
        }

        if ($claims->nbf !== null && $claims->nbf > $now + $skew) {
            return TokenVerification::failure(self::REASON_TOKEN_EARLY);
        }

        if ($claims->exp < $now - $skew) {
            return TokenVerification::failure(self::REASON_TOKEN_EXPIRED);
        }

        if ($options->expectedAud !== null) {
            if ($claims->aud !== $options->expectedAud) {
                return TokenVerification::failure(self::REASON_AUD_MISMATCH);
            }
        }

        if ($options->expectedPath !== null) {
            if ($path === null || !$this->pathMatches($path, $options->expectedPath)) {
                return TokenVerification::failure(self::REASON_PATH_MISMATCH);
            }
        }

        if (isset($claims->app['bind.path'])) {
            $boundPath = (string) $claims->app['bind.path'];
            if ($path === null || !$this->pathMatches($path, $boundPath)) {
                return TokenVerification::failure(self::REASON_PATH_MISMATCH);
            }
        }

        if ($options->expectedHost !== null) {
            if ($host === null || !hash_equals($options->expectedHost, $host)) {
                return TokenVerification::failure(self::REASON_HOST_MISMATCH);
            }
        }

        if (isset($claims->app['bind.host'])) {
            $boundHost = (string) $claims->app['bind.host'];
            if ($host === null || !hash_equals($boundHost, $host)) {
                return TokenVerification::failure(self::REASON_HOST_MISMATCH);
            }
        }

        if ($options->enforceUaHash) {
            $claimUa = $claims->app['uah'] ?? null;
            if (!is_string($claimUa) || $userAgent === null) {
                return TokenVerification::failure(self::REASON_UA_MISMATCH);
            }

            $expectedUa = Encoder::base64UrlEncode(hash('sha256', $userAgent, true));
            if (!hash_equals($claimUa, $expectedUa)) {
                return TokenVerification::failure(self::REASON_UA_MISMATCH);
            }
        }

        if ($options->requireOneTime && $claims->jti === null) {
            return TokenVerification::failure(self::REASON_ONE_TIME_REQUIRED);
        }

        $returnTo = $claims->app['return_to'] ?? null;
        if (is_string($returnTo) && $options->returnToAllowlist !== null) {
            $callable = $options->returnToAllowlist;
            if (!$callable($returnTo)) {
                return TokenVerification::failure(self::REASON_RETURN_TO_DENIED);
            }
        }

        if ($claims->jti !== null) {
            if (!$this->nonceStore->consume($claims->jti, $claims->exp)) {
                return TokenVerification::failure(self::REASON_REPLAYED);
            }
        }

        return TokenVerification::success($claims);
    }

    public function verifyFromRequest(string $tokenOrUrl, ?VerifyOptions $options = null, ?string $userAgent = null): TokenVerification
    {
        $token = $tokenOrUrl;
        $path = null;
        $host = null;
        if (str_contains($tokenOrUrl, '://')) {
            $parsed = parse_url($tokenOrUrl);
            if ($parsed === false) {
                return TokenVerification::failure(self::REASON_MALFORMED_TOKEN);
            }

            $queryString = $parsed['query'] ?? '';
            parse_str($queryString, $params);
            $token = $params[Token::PARAM_DEFAULT] ?? $tokenOrUrl;

            $path = $parsed['path'] ?? null;
            $host = $parsed['host'] ?? null;
        }

        return $this->verifyToken($token, $options, $userAgent, $path, $host);
    }

    private function pathMatches(string $actualPath, string $expectedPath): bool
    {
        if ($expectedPath === '') {
            return $actualPath === '';
        }

        if (str_ends_with($expectedPath, '*')) {
            $prefix = substr($expectedPath, 0, -1);
            return str_starts_with($actualPath, $prefix);
        }

        return hash_equals($expectedPath, $actualPath);
    }
}
