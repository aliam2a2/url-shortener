<?php

namespace App\Filament\Widgets;

use App\Models\ShortLink;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LinkStatsOverview extends StatsOverviewWidget
{
    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $user = auth()->user();
        $query = ShortLink::query();

        if ($user && ! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        return [
            Stat::make('Total clicks', (string) (clone $query)->sum('clicks_total'))
                ->description('All tracked clicks'),
            Stat::make('Active links', (string) (clone $query)->where('is_active', true)->count())
                ->description('Currently active'),
            Stat::make('Inactive links', (string) (clone $query)->where('is_active', false)->count())
                ->description('Currently inactive'),
            Stat::make('Total links', (string) (clone $query)->count())
                ->description('All short links'),
        ];
    }
}
