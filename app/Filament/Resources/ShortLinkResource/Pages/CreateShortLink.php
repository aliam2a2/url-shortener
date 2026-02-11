<?php

namespace App\Filament\Resources\ShortLinkResource\Pages;

use App\Filament\Resources\ShortLinkResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Auth;

class CreateShortLink extends CreateRecord
{
    protected static string $resource = ShortLinkResource::class;

    protected function beforeCreate(): void
    {
        $user = Auth::user();

        if (! $user || $user->isAdmin()) {
            return;
        }

        $usedCount = $user->shortLinks()->count();
        $quota = max(0, (int) $user->short_links_quota);

        if ($usedCount < $quota) {
            return;
        }

        Notification::make()
            ->danger()
            ->title('Short link quota reached')
            ->body("You reached your quota of {$quota} short links. Please contact an administrator.")
            ->send();

        throw new Halt();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Auth::user();

        if ($user) {
            $data['user_id'] = $user->id;
        }

        return $data;
    }
}
