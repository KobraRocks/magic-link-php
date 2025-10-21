<?php

declare(strict_types=1);

namespace MagicLink\Core;

use MagicLink\Exception\TokenFormatException;

final class Encoder
{
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        if ($data === '') {
            return '';
        }

        if (preg_match('/[^A-Za-z0-9\-_]/', $data) === 1) {
            throw new TokenFormatException('Invalid characters in base64url string.');
        }

        $padding = strlen($data) % 4;
        if ($padding > 0) {
            $data .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) {
            throw new TokenFormatException('Unable to decode base64url string.');
        }

        return $decoded;
    }

    /**
     * @param mixed $value
     */
    public static function canonicalJsonEncode($value): string
    {
        $normalized = self::normalizeForCanonicalJson($value);
        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new TokenFormatException('Failed to encode canonical JSON.');
        }

        return $json;
    }

    /**
     * @return mixed
     */
    private static function normalizeForCanonicalJson($value)
    {
        if (is_array($value)) {
            if (self::isAssociativeArray($value)) {
                ksort($value, SORT_STRING);
                $normalized = [];
                foreach ($value as $key => $item) {
                    $normalized[$key] = self::normalizeForCanonicalJson($item);
                }

                return $normalized;
            }

            $normalized = [];
            foreach ($value as $item) {
                $normalized[] = self::normalizeForCanonicalJson($item);
            }

            return $normalized;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new TokenFormatException('Float values must be finite for canonical JSON.');
            }
        }

        if (is_resource($value)) {
            throw new TokenFormatException('Resources cannot be encoded to JSON.');
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public static function jsonDecodeObject(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new TokenFormatException('Unable to decode JSON: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new TokenFormatException('Decoded JSON must be an object.');
        }

        return $decoded;
    }

    /**
     * @param array<mixed> $array
     */
    private static function isAssociativeArray(array $array): bool
    {
        $expectedIndex = 0;
        foreach ($array as $key => $_) {
            if ($key !== $expectedIndex) {
                return true;
            }

            $expectedIndex++;
        }

        return false;
    }
}
