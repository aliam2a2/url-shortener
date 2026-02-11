<?php

namespace App\Filament\Resources\ShortLinkResource\Pages;

use App\Filament\Resources\ShortLinkResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListShortLinks extends ListRecords
{
    protected static string $resource = ShortLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->disabled(function (): bool {
                    $user = Auth::user();

                    if (! $user || $user->isAdmin()) {
                        return false;
                    }

                    return $user->shortLinks()->count() >= (int) $user->short_links_quota;
                })
                ->tooltip(function (): ?string {
                    $user = Auth::user();

                    if (! $user || $user->isAdmin()) {
                        return null;
                    }

                    if ($user->shortLinks()->count() < (int) $user->short_links_quota) {
                        return null;
                    }

                    return "Quota reached ({$user->short_links_quota} links)";
                }),
        ];
    }
}
