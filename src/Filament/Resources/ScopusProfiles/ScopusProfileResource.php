<?php

namespace Mgknetcom\Scopus\Filament\Resources\ScopusProfiles;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Mgknetcom\Scopus\Enums\SuggestionStatus;
use Mgknetcom\Scopus\Filament\Resources\ScopusProfiles\Pages\CreateScopusProfile;
use Mgknetcom\Scopus\Filament\Resources\ScopusProfiles\Pages\EditScopusProfile;
use Mgknetcom\Scopus\Filament\Resources\ScopusProfiles\Pages\ListScopusProfiles;
use Mgknetcom\Scopus\Jobs\DiscoverScopusWorks;
use Mgknetcom\Scopus\Jobs\SyncScopusProfile;
use Mgknetcom\Scopus\Models\ScopusProfile;
use UnitEnum;

final class ScopusProfileResource extends Resource
{
    protected static ?string $model = ScopusProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Scopus профили';

    protected static ?string $modelLabel = 'Scopus профил';

    protected static ?string $pluralModelLabel = 'Scopus профили';

    protected static ?string $recordTitleAttribute = 'author_id';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return config('scopus-laravel.filament.navigation_group', 'Scopus');
    }

    public static function getNavigationSort(): int
    {
        return (int) config('scopus-laravel.filament.navigation_sort', 80);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Scopus профил')->schema([
                TextInput::make('author_id')
                    ->label('Scopus Author ID')
                    ->required()
                    ->regex('/^\d{10,20}$/')
                    ->unique(ignoreRecord: true)
                    ->maxLength(20),
                TextInput::make('owner_type')
                    ->label('Клас на собственика')
                    ->helperText('Напр. App\\Models\\User. Може да се попълни автоматично от host приложението.')
                    ->requiredWith('owner_id')
                    ->maxLength(255),
                TextInput::make('owner_id')
                    ->label('ID на собственика')
                    ->numeric()
                    ->requiredWith('owner_type')
                    ->minValue(1),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_synced_at', 'desc')
            ->columns([
                TextColumn::make('author_id')->label('Author ID')->searchable()->copyable()->sortable(),
                TextColumn::make('owner_label')->label('Собственик')->state(fn (ScopusProfile $record) => $record->ownerLabel()),
                TextColumn::make('indexed_name')->label('Scopus име')->searchable()->toggleable(),
                TextColumn::make('citation_count')->label('Цитирания')->numeric()->sortable(),
                TextColumn::make('cited_by_count')->label('Цитиращи документи')->numeric()->sortable()->toggleable(),
                TextColumn::make('document_count')->label('Документи')->numeric()->sortable(),
                TextColumn::make('h_index')->label('h-index')->placeholder('—')->numeric()->sortable(),
                TextColumn::make('coauthor_count')->label('Съавтори')->numeric()->sortable()->toggleable(),
                TextColumn::make('pending_suggestions_count')->label('Нови предложения')->numeric()->badge(),
                TextColumn::make('sync_status')->label('Статус')->badge(),
                TextColumn::make('last_synced_at')->label('Последно обновяване')->dateTime('d.m.Y H:i')->placeholder('Никога')->sortable(),
            ])
            ->recordActions([
                Action::make('sync')
                    ->label('Обнови метрики')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->action(function (ScopusProfile $record): void {
                        SyncScopusProfile::dispatch($record->id);
                        Notification::make()->title('Синхронизацията е добавена в опашката.')->success()->send();
                    }),
                Action::make('discover')
                    ->label('Потърси публикации')
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->action(function (ScopusProfile $record): void {
                        DiscoverScopusWorks::dispatch($record->id);
                        Notification::make()->title('Търсенето е добавено в опашката.')->success()->send();
                    }),
                EditAction::make(),
                DeleteAction::make()->requiresConfirmation(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()->requiresConfirmation()]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('owner')
            ->withCount([
                'suggestions as pending_suggestions_count' => fn (Builder $query) => $query->where('status', SuggestionStatus::Pending->value),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScopusProfiles::route('/'),
            'create' => CreateScopusProfile::route('/create'),
            'edit' => EditScopusProfile::route('/{record}/edit'),
        ];
    }
}
