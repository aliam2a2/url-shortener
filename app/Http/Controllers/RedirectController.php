<?php

namespace App\Http\Controllers;

use App\Models\ShortLink;
use App\Support\ShortCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RedirectController extends Controller
{
    public function __invoke(string $code): RedirectResponse
    {
        if (! ShortCode::isValid($code)) {
            throw new NotFoundHttpException();
        }

        $cacheKey = sprintf('sl:code:%s', $code);
        $cacheTtlSeconds = 60 * 60 * 24 * 30;

        /** @var array{id:int,url:string,is_permanent:bool}|null $payload */
        $payload = Cache::store('redis')->remember($cacheKey, $cacheTtlSeconds, static function () use ($code): ?array {
            $shortLink = ShortLink::query()
                ->where('code', $code)
                ->where('is_active', true)
                ->first();

            if ($shortLink === null) {
                return null;
            }

            return [
                'id' => (int) $shortLink->id,
                'url' => $shortLink->getDestinationUrl(),
                'is_permanent' => (bool) $shortLink->is_permanent,
            ];
        });

        if ($payload === null) {
            throw new NotFoundHttpException();
        }

        if (! $this->isSafeRedirectUrl($payload['url'])) {
            throw new NotFoundHttpException();
        }

        Redis::connection()->pipeline(static function ($pipeline) use ($payload): void {
            $pipeline->incr(sprintf('sl:clicks:%d', $payload['id']));
            $pipeline->sadd('sl:dirty', (string) $payload['id']);
        });

        return redirect()
            ->away($payload['url'], $payload['is_permanent'] ? 301 : 302)
            ->header('Cache-Control', 'no-store');
    }

    private function isSafeRedirectUrl(string $url): bool
    {
        $normalized = strtolower($url);

        if (! str_starts_with($normalized, 'http://') && ! str_starts_with($normalized, 'https://')) {
            return false;
        }

        return ! str_contains($url, "\r") && ! str_contains($url, "\n");
    }
}
