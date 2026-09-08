<?php

namespace Mgknetcom\Scopus\Filament\Resources\ScopusSuggestions;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Mgknetcom\Scopus\Actions\ImportSuggestion;
use Mgknetcom\Scopus\Enums\SuggestionStatus;
use Mgknetcom\Scopus\Filament\Resources\ScopusSuggestions\Pages\ListScopusSuggestions;
use Mgknetcom\Scopus\Models\ScopusSuggestion;
use Throwable;
use UnitEnum;

final class ScopusSuggestionResource extends Resource
{
    protected static ?string $model = ScopusSuggestion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Scopus предложения';

    protected static ?string $modelLabel = 'Scopus предложение';

    protected static ?string $pluralModelLabel = 'Scopus предложения';

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return config('scopus-laravel.filament.navigation_group', 'Scopus');
    }

    public static function getNavigationSort(): int
    {
        return (int) config('scopus-laravel.filament.navigation_sort', 80) + 1;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('discovered_at', 'desc')
            ->columns([
                TextColumn::make('profile.author_id')->label('Автор')->searchable()->sortable(),
                TextColumn::make('title')->label('Заглавие')->searchable()->wrap()->limit(80),
                TextColumn::make('authors')->label('Автори')->searchable()->limit(60)->toggleable(),
                TextColumn::make('year')->label('Година')->sortable(),
                TextColumn::make('document_type')->label('Scopus тип')->placeholder('—')->toggleable(),
                TextColumn::make('mapped_type')->label('Тип')->badge(),
                TextColumn::make('doi')->label('DOI')->searchable()->copyable()->placeholder('—')->toggleable(),
                TextColumn::make('citation_count')->label('Цитирания')->numeric()->sortable(),
                TextColumn::make('status')->label('Статус')->badge()->sortable(),
                TextColumn::make('discovered_at')->label('Открита')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(collect(SuggestionStatus::cases())->mapWithKeys(fn (SuggestionStatus $status) => [
                        $status->value => match ($status) {
                            SuggestionStatus::Pending => 'Очаква решение',
                            SuggestionStatus::Imported => 'Импортирана',
                            SuggestionStatus::Ignored => 'Игнорирана',
                            SuggestionStatus::Matched => 'Вече съществува',
                            SuggestionStatus::Failed => 'Грешка',
                        },
                    ])->all()),
            ])
            ->recordActions([
                Action::make('import')
                    ->label('Импортирай като предложение')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn (ScopusSuggestion $record): bool => $record->status === SuggestionStatus::Pending)
                    ->requiresConfirmation()
                    ->action(function (ScopusSuggestion $record): void {
                        try {
                            app(ImportSuggestion::class)->execute($record);
                            Notification::make()->title('Публикацията е импортирана като предложение.')->success()->send();
                        } catch (Throwable $exception) {
                            Notification::make()->title('Импортирането е неуспешно.')->body($exception->getMessage())->danger()->send();
                        }
                    }),
                Action::make('ignore')
                    ->label('Игнорирай')
                    ->visible(fn (ScopusSuggestion $record): bool => $record->status === SuggestionStatus::Pending)
                    ->requiresConfirmation()
                    ->action(fn (ScopusSuggestion $record) => $record->update([
                        'status' => SuggestionStatus::Ignored,
                        'resolved_at' => now(),
                    ])),
                Action::make('reopen')
                    ->label('Върни за преглед')
                    ->visible(fn (ScopusSuggestion $record): bool => in_array($record->status, [SuggestionStatus::Ignored, SuggestionStatus::Failed], true))
                    ->action(fn (ScopusSuggestion $record) => $record->update([
                        'status' => SuggestionStatus::Pending,
                        'resolved_at' => null,
                    ])),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('profile');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListScopusSuggestions::route('/')];
    }
}
