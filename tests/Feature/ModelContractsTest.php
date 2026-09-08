<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Mgknetcom\Scopus\Contracts\SuggestionImporter;
use Mgknetcom\Scopus\Enums\ScopusDocumentType;
use Mgknetcom\Scopus\Enums\SuggestionStatus;
use Mgknetcom\Scopus\Exceptions\ScopusConfigurationException;
use Mgknetcom\Scopus\Models\ScopusProfile;
use Mgknetcom\Scopus\Models\ScopusSuggestion;
use Mgknetcom\Scopus\Models\ScopusSyncRun;
use Mgknetcom\Scopus\Support\NullSuggestionImporter;

it('requires owner type and ID together', function (array $owner): void {
    expect(fn () => ScopusProfile::query()->create(array_merge([
        'author_id' => '57212345690',
    ], $owner)))->toThrow(ValidationException::class, 'provided together');
})->with([
    'type only' => [['owner_type' => ScopusProfile::class]],
    'ID only' => [['owner_id' => 1]],
]);

it('rejects invalid and missing owner models', function (string $ownerType, int $ownerId, string $message): void {
    expect(fn () => ScopusProfile::query()->create([
        'author_id' => '57212345691',
        'owner_type' => $ownerType,
        'owner_id' => $ownerId,
    ]))->toThrow(ValidationException::class, $message);
})->with([
    'invalid model class' => [stdClass::class, 1, 'model class is invalid'],
    'missing model row' => [ScopusProfile::class, 999999, 'does not exist'],
]);

it('resolves profile relations and readable owner labels', function (): void {
    $profile = ScopusProfile::query()->create(['author_id' => '57212345692']);
    $run = ScopusSyncRun::query()->create([
        'scopus_profile_id' => $profile->id,
        'operation' => 'profile',
        'status' => 'success',
        'started_at' => now(),
    ]);

    $namedOwner = new class extends Model {};
    $namedOwner->setAttribute('name', 'Readable owner');
    $profile->setRelation('owner', $namedOwner);

    expect($profile->syncRuns()->sole()->is($run))->toBeTrue()
        ->and($profile->ownerLabel())->toBe('Readable owner');

    $fallbackOwner = new class extends Model {};
    $fallbackOwner->setAttribute('id', 42);
    $profile->setRelation('owner', $fallbackOwner);

    expect($profile->ownerLabel())->toEndWith(' #42');
});

it('resolves suggestion and sync run model helpers', function (): void {
    $profile = ScopusProfile::query()->create(['author_id' => '57212345693']);
    $imported = ScopusProfile::query()->create(['author_id' => '57212345694']);
    $suggestion = ScopusSuggestion::query()->create([
        'scopus_profile_id' => $profile->id,
        'eid' => '2-s2.0-model-contract',
        'title' => 'Model contract',
        'mapped_type' => 'other',
        'status' => 'pending',
        'imported_model_type' => ScopusProfile::class,
        'imported_model_id' => $imported->id,
        'discovered_at' => now(),
    ]);
    $run = ScopusSyncRun::query()->create([
        'scopus_profile_id' => $profile->id,
        'operation' => 'discovery',
        'status' => 'success',
        'started_at' => now(),
    ]);

    expect($suggestion->importedModel()->first()->is($imported))->toBeTrue()
        ->and($suggestion->isPending())->toBeTrue()
        ->and($run->profile()->first()->is($profile))->toBeTrue()
        ->and(ScopusDocumentType::fromDescription('Book'))->toBe(ScopusDocumentType::Book);

    $suggestion->update(['status' => SuggestionStatus::Ignored]);
    expect($suggestion->fresh()->isPending())->toBeFalse();
});

it('the null importer explains the missing host integration', function (): void {
    $suggestion = new ScopusSuggestion;

    expect(fn () => (new NullSuggestionImporter)->import($suggestion))
        ->toThrow(ScopusConfigurationException::class, 'Bind '.SuggestionImporter::class);
});
