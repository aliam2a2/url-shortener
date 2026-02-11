<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShortLinkResource\Pages;
use App\Models\ShortLink;
use App\Models\User;
use App\Support\ShortCode;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Redis;

class ShortLinkResource extends Resource
{
    protected static ?string $model = ShortLink::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-link';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Wizard::make([
                Step::make('Target URL')
                    ->description('Provide the destination URL.')
                    ->schema([
                        static::targetUrlField(),
                    ]),
                Step::make('UTM Parameters')
                    ->description('Optional campaign tracking values.')
                    ->schema([
                        static::utmSourceField(),
                        static::utmMediumField(),
                        static::utmCampaignField(),
                        static::utmTermField(),
                        static::utmContentField(),
                        Forms\Components\Toggle::make('extract_utm_from_url')
                            ->label('Extract UTM values from target URL')
                            ->helperText('If enabled, the form will read UTM params from the URL when present.')
                            ->default(false)
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(function (bool $state, Get $get, Set $set): void {
                                if (! $state) {
                                    return;
                                }

                                static::applyUtmValuesFromUrl($get, $set);
                            }),
                    ])
                    ->columns(2),
                Step::make('Slug and status')
                    ->description('Choose or generate the short slug.')
                    ->schema([
                        static::slugField(),
                        Forms\Components\Toggle::make('is_active')
                            ->default(true)
                            ->required(),
                        Forms\Components\Toggle::make('is_permanent')
                            ->default(false)
                            ->required(),
                    ])
                    ->columns(2),
            ])
                ->hidden(fn (string $operation): bool => $operation === 'edit'),
            Group::make([
                static::sourceUrlField(),
                static::targetUrlField(),
                static::utmSourceField(),
                static::utmMediumField(),
                static::utmCampaignField(),
                static::utmTermField(),
                static::utmContentField(),
                static::slugField(),
                Forms\Components\Toggle::make('is_active')
                    ->default(true)
                    ->required(),
                Forms\Components\Toggle::make('is_permanent')
                    ->default(false)
                    ->required(),
            ])
                ->columns(2)
                ->hidden(fn (string $operation): bool => $operation === 'create'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Source / Slug')
                    ->state(fn (ShortLink $record): string => rtrim((string) config('app.url'), '/') . '/' . $record->code)
                    ->searchable(['code'])
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Source URL copied')
                    ->copyMessageDuration(1500),
                Tables\Columns\TextColumn::make('long_url')
                    ->label('Target URL')
                    ->searchable()
                    ->limit(80)
                    ->tooltip(fn (ShortLink $record): string => $record->long_url)
                    ->copyable()
                    ->copyMessage('Target URL copied')
                    ->copyMessageDuration(1500),
                Tables\Columns\TextColumn::make('utm_summary')
                    ->label('UTM')
                    ->state(fn (ShortLink $record): string => static::getUtmSummary($record))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('utm_source')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('utm_medium')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('utm_campaign')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('clicks_total')
                    ->label('Click Count')
                    ->sortable(),
                Tables\Columns\TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Owner')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('All statuses')
                    ->trueLabel('Active')
                    ->falseLabel('Inactive'),
                TernaryFilter::make('is_permanent')
                    ->label('Redirect type')
                    ->placeholder('All')
                    ->trueLabel('Permanent')
                    ->falseLabel('Temporary'),
                SelectFilter::make('user_id')
                    ->label('Owner')
                    ->relationship('user', 'name')
                    ->visible(function (): bool {
                        $user = Auth::user();

                        return ($user instanceof User) && $user->isAdmin();
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
                Action::make('invalidateCache')
                    ->label('Invalidate Cache')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(static function (ShortLink $record): void {
                        Redis::connection()->del(sprintf('sl:code:%s', $record->code));
                    })
                    ->successNotificationTitle('Cache invalidated'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextEntry::make('source_url')
                    ->label('Source URL')
                    ->state(fn (ShortLink $record): string => rtrim((string) config('app.url'), '/') . '/' . $record->code)
                    ->copyable()
                    ->copyMessage('Source URL copied')
                    ->copyMessageDuration(1500),
                TextEntry::make('long_url')
                    ->label('Target URL')
                    ->copyable()
                    ->copyMessage('Target URL copied')
                    ->copyMessageDuration(1500),
                TextEntry::make('code')
                    ->label('Slug'),
                TextEntry::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
                TextEntry::make('clicks_total')
                    ->label('Click Count'),
                TextEntry::make('created_at')
                    ->dateTime(),
            ])
            ->columns(2);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        if (! ($user instanceof User) || $user->isAdmin()) {
            return $query;
        }

        return $query->where('user_id', $user->id);
    }

    /**
     * @return array<string, string>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShortLinks::route('/'),
            'create' => Pages\CreateShortLink::route('/create'),
            'view' => Pages\ViewShortLink::route('/{record}'),
            'edit' => Pages\EditShortLink::route('/{record}/edit'),
        ];
    }

    private static function targetUrlField(): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make('long_url')
            ->label('Target URL')
            ->required()
            ->url()
            ->maxLength(2048)
            ->rules(['regex:/^https?:\\/\\//i'])
            ->copyable(copyMessage: 'Target URL copied', copyMessageDuration: 1500)
            ->live(onBlur: true)
            ->afterStateUpdated(static function (?string $state, Set $set): void {
                if (blank($state)) {
                    return;
                }

                static::applyParsedUtmValues($state, $set);
            })
            ->columnSpanFull();
    }

    private static function sourceUrlField(): TextInput
    {
        return TextInput::make('source_url')
            ->label('Source URL')
            ->readOnly()
            ->dehydrated(false)
            ->afterStateHydrated(static function (TextInput $component, ?ShortLink $record): void {
                if ($record === null) {
                    $component->state(null);

                    return;
                }

                $component->state(rtrim((string) config('app.url'), '/') . '/' . $record->code);
            })
            ->copyable(copyMessage: 'Source URL copied', copyMessageDuration: 1500)
            ->columnSpanFull();
    }

    private static function utmSourceField(): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make('utm_source')
            ->label('UTM Source')
            ->maxLength(255)
            ->columnSpan(1);
    }

    private static function utmMediumField(): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make('utm_medium')
            ->label('UTM Medium')
            ->maxLength(255)
            ->columnSpan(1);
    }

    private static function utmCampaignField(): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make('utm_campaign')
            ->label('UTM Campaign')
            ->maxLength(255)
            ->columnSpan(1);
    }

    private static function utmTermField(): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make('utm_term')
            ->label('UTM Term (optional)')
            ->maxLength(255)
            ->columnSpan(1);
    }

    private static function utmContentField(): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make('utm_content')
            ->label('UTM Content (optional)')
            ->maxLength(255)
            ->columnSpan(1);
    }

    private static function slugField(): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make('code')
            ->label('Slug')
            ->nullable()
            ->maxLength(8)
            ->helperText('Allowed: letters and numbers, max 8 characters. Leave empty for auto generation.')
            ->regex('/^[0-9A-Za-z]{1,8}$/')
            ->rule(function (): \Closure {
                return static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || blank($value)) {
                        return;
                    }

                    $lowerSlug = strtolower($value);

                    foreach (ShortCode::getReservedPrefixes() as $reservedPrefix) {
                        if (str_starts_with($lowerSlug, $reservedPrefix)) {
                            $fail("The {$attribute} uses a reserved prefix.");
                        }
                    }
                };
            })
            ->unique(ignoreRecord: true)
            ->suffixAction(
                Action::make('generateSlug')
                    ->icon('heroicon-o-sparkles')
                    ->tooltip('Generate slug')
                    ->action(static function (Set $set): void {
                        $set('code', ShortCode::generate());
                    }),
            );
    }

    private static function applyUtmValuesFromUrl(Get $get, Set $set): void
    {
        $url = $get->string('long_url', isNullable: true);

        if (blank($url)) {
            return;
        }

        static::applyParsedUtmValues($url, $set);
    }

    private static function applyParsedUtmValues(string $url, Set $set): void
    {
        $utmValues = ShortLink::extractUtmValues($url);

        if (empty(array_filter($utmValues, static fn (?string $value): bool => filled($value)))) {
            return;
        }

        foreach ($utmValues as $field => $value) {
            $set($field, $value);
        }
    }

    private static function getUtmSummary(ShortLink $record): string
    {
        $utmSummary = array_filter([
            "src={$record->utm_source}",
            "med={$record->utm_medium}",
            "cmp={$record->utm_campaign}",
            filled($record->utm_term) ? "term={$record->utm_term}" : null,
            filled($record->utm_content) ? "cnt={$record->utm_content}" : null,
        ]);

        if ($utmSummary === []) {
            return '-';
        }

        return implode(' | ', $utmSummary);
    }
}
