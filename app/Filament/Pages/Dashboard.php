<?php

namespace App\Filament\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Auth;

class Dashboard extends BaseDashboard
{
    protected function getHeaderActions(): array
    {
        return [
            Action::make('addUser')
                ->label('Add User')
                ->icon('heroicon-o-user-plus')
                ->url(UserResource::getUrl('create'))
                ->visible(function (): bool {
                    $user = Auth::user();

                    return ($user instanceof User) && $user->isAdmin();
                }),
        ];
    }
}
