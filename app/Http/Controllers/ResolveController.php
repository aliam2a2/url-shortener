<?php

namespace App\Http\Controllers;

use App\Models\ShortLink;
use App\Support\ShortCode;
use Illuminate\Http\JsonResponse;

class ResolveController extends Controller
{
    public function __invoke(string $code): JsonResponse
    {
        if (! ShortCode::isValid($code)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $shortLink = ShortLink::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if ($shortLink === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json([
            'id' => (int) $shortLink->id,
            'url' => $shortLink->getDestinationUrl(),
            'active' => (bool) $shortLink->is_active,
            'is_permanent' => (bool) $shortLink->is_permanent,
        ]);
    }
}
