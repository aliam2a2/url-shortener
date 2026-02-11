<?php

namespace App\Filament\Resources\ShortLinkResource\Pages;

use App\Filament\Resources\ShortLinkResource;
use App\Models\ShortLink;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditShortLink extends EditRecord
{
    protected static string $resource = ShortLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
        $hasUtmData = false;

        foreach ($utmKeys as $utmKey) {
            if (filled($data[$utmKey] ?? null)) {
                $hasUtmData = true;
                break;
            }
        }

        if ($hasUtmData || blank($data['long_url'] ?? null)) {
            return $data;
        }

        $parsed = ShortLink::extractUtmValues((string) $data['long_url']);

        return array_merge($data, $parsed);
    }
}
