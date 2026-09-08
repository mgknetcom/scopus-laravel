<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Mgknetcom\Scopus\Contracts\SuggestionImporter;
use Mgknetcom\Scopus\Data\PublicationReference;
use Mgknetcom\Scopus\Enums\SuggestionStatus;
use Mgknetcom\Scopus\Filament\Resources\ScopusProfiles\Pages\EditScopusProfile;
use Mgknetcom\Scopus\Filament\Resources\ScopusProfiles\Pages\ListScopusProfiles;
use Mgknetcom\Scopus\Filament\Resources\ScopusProfiles\ScopusProfileResource;
use Mgknetcom\Scopus\Filament\Resources\ScopusSuggestions\Pages\ListScopusSuggestions;
use Mgknetcom\Scopus\Filament\Resources\ScopusSuggestions\ScopusSuggestionResource;
use Mgknetcom\Scopus\Jobs\DiscoverScopusWorks;
use Mgknetcom\Scopus\Jobs\SyncScopusProfile;
use Mgknetcom\Scopus\Models\ScopusProfile;
use Mgknetcom\Scopus\Models\ScopusSuggestion;

it('ignores and reopens suggestions through Filament record actions', function (): void {
    $profile = ScopusProfile::query()->create(['author_id' => '57212345680']);
    $suggestion = ScopusSuggestion::query()->create([
        'scopus_profile_id' => $profile->id,
        'eid' => '2-s2.0-filament-ignore',
        'title' => 'Filament action work',
        'mapped_type' => 'article',
        'status' => 'pending',
        'discovered_at' => now(),
    ]);

    Livewire::test(ListScopusSuggestions::class)
        ->assertActionVisible(TestAction::make('ignore')->table($suggestion))
        ->callAction(TestAction::make('ignore')->table($suggestion));

    expect($suggestion->fresh()->status)->toBe(SuggestionStatus::Ignored)
        ->and($suggestion->fresh()->resolved_at)->not->toBeNull();

    Livewire::test(ListScopusSuggestions::class)
        ->assertActionVisible(TestAction::make('reopen')->table($suggestion->fresh()))
        ->callAction(TestAction::make('reopen')->table($suggestion->fresh()));

    expect($suggestion->fresh()->status)->toBe(SuggestionStatus::Pending)
        ->and($suggestion->fresh()->resolved_at)->toBeNull();
});

it('imports a suggestion through the Filament action', function (): void {
    app()->instance(SuggestionImporter::class, new class implements SuggestionImporter
    {
        public function import(ScopusSuggestion $suggestion): PublicationReference
        {
            return new PublicationReference('App\\Models\\Publication', 501, 'Imported in Filament');
        }
    });

    $profile = ScopusProfile::query()->create(['author_id' => '57212345681']);
    $suggestion = ScopusSuggestion::query()->create([
        'scopus_profile_id' => $profile->id,
        'eid' => '2-s2.0-filament-import',
        'title' => 'Filament import work',
        'mapped_type' => 'article',
        'status' => 'pending',
        'discovered_at' => now(),
    ]);

    Livewire::test(ListScopusSuggestions::class)
        ->assertActionVisible(TestAction::make('import')->table($suggestion))
        ->callAction(TestAction::make('import')->table($suggestion));

    expect($suggestion->fresh()->status)->toBe(SuggestionStatus::Imported)
        ->and($suggestion->fresh()->imported_model_id)->toBe(501);
});

it('queues profile synchronization and discovery through Filament actions', function (): void {
    Bus::fake();
    $profile = ScopusProfile::query()->create(['author_id' => '57212345682']);

    Livewire::test(ListScopusProfiles::class)
        ->callAction(TestAction::make('sync')->table($profile))
        ->callAction(TestAction::make('discover')->table($profile));

    Bus::assertDispatched(SyncScopusProfile::class, fn (SyncScopusProfile $job): bool => $job->profileId === $profile->id);
    Bus::assertDispatched(DiscoverScopusWorks::class, fn (DiscoverScopusWorks $job): bool => $job->profileId === $profile->id);
});

it('keeps suggestions read-only and exposes configured navigation metadata', function (): void {
    config()->set('scopus-laravel.filament.navigation_group', 'Research imports');
    config()->set('scopus-laravel.filament.navigation_sort', 25);

    expect(ScopusProfileResource::getNavigationGroup())->toBe('Research imports')
        ->and(ScopusProfileResource::getNavigationSort())->toBe(25)
        ->and(ScopusSuggestionResource::getNavigationGroup())->toBe('Research imports')
        ->and(ScopusSuggestionResource::getNavigationSort())->toBe(26)
        ->and(ScopusSuggestionResource::canCreate())->toBeFalse()
        ->and(ScopusSuggestionResource::canEdit(new ScopusSuggestion))->toBeFalse();
});

it('reports importer failures from the Filament action without resolving the suggestion', function (): void {
    app()->instance(SuggestionImporter::class, new class implements SuggestionImporter
    {
        public function import(ScopusSuggestion $suggestion): PublicationReference
        {
            throw new RuntimeException('Host import failed.');
        }
    });

    $profile = ScopusProfile::query()->create(['author_id' => '57212345698']);
    $suggestion = ScopusSuggestion::query()->create([
        'scopus_profile_id' => $profile->id,
        'eid' => '2-s2.0-filament-failure',
        'title' => 'Failed Filament import',
        'mapped_type' => 'other',
        'status' => 'pending',
        'discovered_at' => now(),
    ]);

    Livewire::test(ListScopusSuggestions::class)
        ->callAction(TestAction::make('import')->table($suggestion));

    expect($suggestion->fresh()->status)->toBe(SuggestionStatus::Pending);
});

it('exposes the delete action on the profile edit page', function (): void {
    $profile = ScopusProfile::query()->create(['author_id' => '57212345699']);

    Livewire::test(EditScopusProfile::class, ['record' => $profile->getRouteKey()])
        ->assertActionExists('delete');
});
