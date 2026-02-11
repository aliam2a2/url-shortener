<?php

namespace App\Models;

use App\Support\ShortCode;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class ShortLink extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'code',
        'long_url',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'is_active',
        'is_permanent',
        'clicks_total',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'user_id' => 'integer',
        'is_active' => 'boolean',
        'is_permanent' => 'boolean',
        'clicks_total' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $shortLink): void {
            if (empty($shortLink->code)) {
                $shortLink->code = ShortCode::generate();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getDestinationUrl(): string
    {
        $urlParts = parse_url($this->long_url) ?: [];

        $existingQuery = [];
        if (! empty($urlParts['query'])) {
            parse_str((string) $urlParts['query'], $existingQuery);
        }

        unset(
            $existingQuery['utm_source'],
            $existingQuery['utm_medium'],
            $existingQuery['utm_campaign'],
            $existingQuery['utm_term'],
            $existingQuery['utm_content'],
        );

        $utmValues = array_filter([
            'utm_source' => self::normalizeNullableString($this->utm_source),
            'utm_medium' => self::normalizeNullableString($this->utm_medium),
            'utm_campaign' => self::normalizeNullableString($this->utm_campaign),
            'utm_term' => self::normalizeNullableString($this->utm_term),
            'utm_content' => self::normalizeNullableString($this->utm_content),
        ], static fn (?string $value): bool => $value !== null);

        $query = array_merge($existingQuery, $utmValues);
        $urlParts['query'] = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return self::buildUrlFromParts($urlParts);
    }

    /**
     * @return array<string, string|null>
     */
    public static function extractUtmValues(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || blank($query)) {
            return [
                'utm_source' => null,
                'utm_medium' => null,
                'utm_campaign' => null,
                'utm_term' => null,
                'utm_content' => null,
            ];
        }

        parse_str($query, $queryValues);

        return [
            'utm_source' => self::normalizeNullableString($queryValues['utm_source'] ?? null),
            'utm_medium' => self::normalizeNullableString($queryValues['utm_medium'] ?? null),
            'utm_campaign' => self::normalizeNullableString($queryValues['utm_campaign'] ?? null),
            'utm_term' => self::normalizeNullableString($queryValues['utm_term'] ?? null),
            'utm_content' => self::normalizeNullableString($queryValues['utm_content'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private static function buildUrlFromParts(array $parts): string
    {
        $scheme = isset($parts['scheme']) ? "{$parts['scheme']}://" : '';
        $user = $parts['user'] ?? '';
        $pass = isset($parts['pass']) ? ":{$parts['pass']}" : '';
        $auth = $user || $pass ? "{$user}{$pass}@" : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ":{$parts['port']}" : '';
        $path = $parts['path'] ?? '';
        $query = ! empty($parts['query']) ? "?{$parts['query']}" : '';
        $fragment = isset($parts['fragment']) ? "#{$parts['fragment']}" : '';

        return "{$scheme}{$auth}{$host}{$port}{$path}{$query}{$fragment}";
    }

    private static function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
