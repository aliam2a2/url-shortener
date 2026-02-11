<?php

namespace App\Support;

use App\Models\ShortLink;
use RuntimeException;

class ShortCode
{
    private const LENGTH = 8;

    private const MAX_ATTEMPTS = 10;

    /**
     * @var list<string>
     */
    private const RESERVED_PREFIXES = [
        'admin',
        'api',
        '_internal',
        'healthz',
    ];

    private const SLUG_PATTERN = '/^[0-9A-Za-z]{1,8}$/';

    public static function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = self::randomAlphaNumeric(self::LENGTH);

            if (! self::isValid($candidate)) {
                continue;
            }

            if (! ShortLink::query()->where('code', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate a unique short code.');
    }

    public static function isValid(string $code): bool
    {
        if (! preg_match(self::SLUG_PATTERN, $code)) {
            return false;
        }

        $lowerCode = strtolower($code);

        foreach (self::RESERVED_PREFIXES as $reservedPrefix) {
            if (str_starts_with($lowerCode, $reservedPrefix)) {
                return false;
            }
        }

        return true;
    }

    private static function randomAlphaNumeric(int $length): string
    {
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $alphabetLength = strlen($alphabet);
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $index = random_int(0, $alphabetLength - 1);
            $code .= $alphabet[$index];
        }

        return $code;
    }

    /**
     * @return array<string>
     */
    public static function getReservedPrefixes(): array
    {
        return self::RESERVED_PREFIXES;
    }
}
