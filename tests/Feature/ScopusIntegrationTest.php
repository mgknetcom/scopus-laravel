<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mgknetcom\Scopus\Actions\ImportSuggestion;
use Mgknetcom\Scopus\Contracts\PublicationMatcher;
use Mgknetcom\Scopus\Contracts\SuggestionImporter;
use Mgknetcom\Scopus\Data\PublicationReference;
use Mgknetcom\Scopus\Data\ScopusWork;
use Mgknetcom\Scopus\Enums\SuggestionStatus;
use Mgknetcom\Scopus\Enums\SyncStatus;
use Mgknetcom\Scopus\Events\ProfileSynced;
use Mgknetcom\Scopus\Events\SuggestionImported;
use Mgknetcom\Scopus\Events\SyncFailed;
use Mgknetcom\Scopus\Events\WorkDiscovered;
use Mgknetcom\Scopus\Exceptions\ScopusApiException;
use Mgknetcom\Scopus\Exceptions\ScopusConfigurationException;
use Mgknetcom\Scopus\Models\ScopusProfile;
use Mgknetcom\Scopus\Models\ScopusSuggestion;
use Mgknetcom\Scopus\Models\ScopusSyncRun;
use Mgknetcom\Scopus\Services\ScopusIntegration;

it('synchronizes author metrics', function (): void {
    Event::fake();
    Http::fake([
        'https://api.elsevier.com/content/author/author_id/*' => Http::response([
            'author-retrieval-response' => [[
                'coredata' => [
                    'citation-count' => '321',
                    'document-count' => '25',
                    'cited-by-count' => '88',
                ],
                'h-index' => '11',
                'coauthor-count' => '17',
                'preferred-name' => ['ce:indexed-name' => 'Ivanov I.'],
            ]],
        ]),
    ]);

    $profile = ScopusProfile::query()->create(['author_id' => '57212345678']);
    app(ScopusIntegration::class)->syncProfile($profile);
    $profile->refresh();

    expect($profile->citation_count)->toBe(321)
        ->and($profile->document_count)->toBe(25)
        ->and($profile->h_index)->toBe(11)
        ->and($profile->cited_by_count)->toBe(88)
        ->and($profile->coauthor_count)->toBe(17)
        ->and($profile->indexed_name)->toBe('Ivanov I.')
        ->and($profile->sync_status)->toBe(SyncStatus::Success)
        ->and($profile->last_synced_at)->not->toBeNull()
        ->and(ScopusSyncRun::query()->where('operation', 'profile')->where('status', 'success')->exists())->toBeTrue();

    Event::assertDispatched(ProfileSynced::class);
});

it('discovers detailed pending suggestions idempotently', function (): void {
    Event::fake();
    Http::fake([
        'https://api.elsevier.com/content/search/scopus*' => Http::sequence()
            ->push([
                'search-results' => [
                    'opensearch:totalResults' => '1',
                    'entry' => [[
                        'eid' => '2-s2.0-123',
                        'dc:identifier' => 'SCOPUS_ID:123',
                        'dc:title' => 'A Scopus paper',
                        'prism:doi' => '10.1000/example',
                        'prism:coverDate' => '2024-01-01',
                        'prism:publicationName' => 'Journal of Tests',
                        'citedby-count' => '7',
                        'subtypeDescription' => 'Article',
                    ]],
                ],
            ])
            ->push([
                'search-results' => [
                    'opensearch:totalResults' => '1',
                    'entry' => [[
                        'eid' => '2-s2.0-123',
                        'dc:identifier' => 'SCOPUS_ID:123',
                        'dc:title' => 'A Scopus paper',
                        'prism:doi' => '10.1000/example',
                        'prism:coverDate' => '2024-01-01',
                        'prism:publicationName' => 'Journal of Tests',
                        'citedby-count' => '8',
                        'subtypeDescription' => 'Article',
                    ]],
                ],
            ]),
        'https://api.elsevier.com/content/abstract/eid/*' => Http::response([
            'abstracts-retrieval-response' => [
                'coredata' => [
                    'dc:title' => 'A Scopus paper',
                    'prism:doi' => '10.1000/example',
                    'prism:coverDate' => '2024-01-01',
                    'citedby-count' => '7',
                    'subtypeDescription' => 'Article',
                ],
                'authors' => ['author' => ['surname' => 'Ivanov', 'initials' => 'I.']],
                'item' => ['bibrecord' => ['head' => ['source' => []]]],
            ],
        ]),
    ]);

    $profile = ScopusProfile::query()->create(['author_id' => '57212345678']);
    $first = app(ScopusIntegration::class)->discover($profile);
    $second = app(ScopusIntegration::class)->discover($profile);

    expect($first->found)->toBe(1)
        ->and($first->created)->toBe(1)
        ->and($second->updated)->toBe(1)
        ->and(ScopusSuggestion::query()->count())->toBe(1);

    $suggestion = ScopusSuggestion::query()->firstOrFail();
    expect($suggestion->status)->toBe(SuggestionStatus::Pending)
        ->and($suggestion->authors)->toBe('Ivanov, I.')
        ->and($suggestion->citation_count)->toBe(8);

    Http::assertSentCount(3); // two searches, one abstract request
    Event::assertDispatchedTimes(WorkDiscovered::class, 2);
});

it('imports a pending suggestion through the host contract once', function (): void {
    Event::fake();
    $importer = new class implements SuggestionImporter
    {
        public int $count = 0;

        public function import(ScopusSuggestion $suggestion): PublicationReference
        {
            $this->count++;

            return new PublicationReference('App\\Models\\Publication', 99, 'Pending authorship created');
        }
    };
    app()->instance(SuggestionImporter::class, $importer);

    $profile = ScopusProfile::query()->create(['author_id' => '57212345678']);
    $suggestion = ScopusSuggestion::query()->create([
        'scopus_profile_id' => $profile->id,
        'eid' => '2-s2.0-999',
        'title' => 'Pending work',
        'mapped_type' => 'article',
        'status' => 'pending',
        'discovered_at' => now(),
    ]);

    $action = app(ImportSuggestion::class);
    $first = $action->execute($suggestion);
    $second = $action->execute($suggestion);

    expect($first->modelId)->toBe(99)
        ->and($second->modelId)->toBe(99)
        ->and($importer->count)->toBe(1)
        ->and($suggestion->fresh()->status)->toBe(SuggestionStatus::Imported);

    Event::assertDispatchedTimes(SuggestionImported::class, 1);
});

it('matches an existing host publication without requesting abstract metadata', function (): void {
    app()->instance(PublicationMatcher::class, new class implements PublicationMatcher
    {
        public function match(ScopusProfile $profile, ScopusWork $work): PublicationReference
        {
            return new PublicationReference('App\\Models\\Publication', 42, 'Matched by DOI');
        }
    });

    Http::fake([
        'https://api.elsevier.com/content/search/scopus*' => Http::response([
            'search-results' => [
                'opensearch:totalResults' => '1',
                'entry' => [[
                    'eid' => '2-s2.0-321',
                    'dc:title' => 'Already stored paper',
                    'prism:doi' => '10.1000/stored',
                ]],
            ],
        ]),
    ]);

    $profile = ScopusProfile::query()->create(['author_id' => '57212345678']);
    $result = app(ScopusIntegration::class)->discover($profile);
    $suggestion = ScopusSuggestion::query()->firstOrFail();

    expect($result->matched)->toBe(1)
        ->and($result->created)->toBe(0)
        ->and($suggestion->status)->toBe(SuggestionStatus::Matched)
        ->and($suggestion->imported_model_type)->toBe('App\\Models\\Publication')
        ->and($suggestion->imported_model_id)->toBe(42)
        ->and($suggestion->match_reason)->toBe('Matched by DOI')
        ->and($suggestion->resolved_at)->not->toBeNull();

    Http::assertSentCount(1);
});

it('stores a failed suggestion when an abstract is permanently unavailable', function (): void {
    Http::fake([
        'https://api.elsevier.com/content/search/scopus*' => Http::response([
            'search-results' => [
                'opensearch:totalResults' => '1',
                'entry' => [[
                    'eid' => '2-s2.0-missing',
                    'dc:title' => 'Unavailable abstract',
                ]],
            ],
        ]),
        'https://api.elsevier.com/content/abstract/eid/*' => Http::response([], 404),
    ]);

    $profile = ScopusProfile::query()->create(['author_id' => '57212345678']);
    $result = app(ScopusIntegration::class)->discover($profile);
    $suggestion = ScopusSuggestion::query()->firstOrFail();

    expect($result->created)->toBe(1)
        ->and($suggestion->status)->toBe(SuggestionStatus::Failed)
        ->and($suggestion->match_reason)->toContain('not found')
        ->and($profile->fresh()->sync_status)->toBe(SyncStatus::Success)
        ->and(ScopusSyncRun::query()->latest('id')->firstOrFail()->status)->toBe(SyncStatus::Success);
});

it('fails the complete discovery run for retryable Scopus errors', function (): void {
    Event::fake();
    Http::fake([
        'https://api.elsevier.com/content/search/scopus*' => Http::response([
            'search-results' => [
                'opensearch:totalResults' => '1',
                'entry' => [[
                    'eid' => '2-s2.0-rate-limited',
                    'dc:title' => 'Retry later',
                ]],
            ],
        ]),
        'https://api.elsevier.com/content/abstract/eid/*' => Http::response([], 429),
    ]);

    $profile = ScopusProfile::query()->create(['author_id' => '57212345678']);

    expect(fn () => app(ScopusIntegration::class)->discover($profile))
        ->toThrow(ScopusApiException::class, 'rate limit');

    expect($profile->fresh()->sync_status)->toBe(SyncStatus::Failed)
        ->and($profile->sync_error)->toContain('rate limit')
        ->and(ScopusSuggestion::query()->count())->toBe(0);

    $run = ScopusSyncRun::query()->latest('id')->firstOrFail();
    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($run->error)->toContain('rate limit')
        ->and($run->finished_at)->not->toBeNull();

    Event::assertDispatched(SyncFailed::class);
});

it('automatically imports a new suggestion when enabled', function (): void {
    config()->set('scopus-laravel.discovery.auto_import', true);

    $importer = new class implements SuggestionImporter
    {
        public int $count = 0;

        public function import(ScopusSuggestion $suggestion): PublicationReference
        {
            $this->count++;

            return new PublicationReference('App\\Models\\Publication', 77, 'Automatically imported');
        }
    };
    app()->instance(SuggestionImporter::class, $importer);

    Http::fake([
        'https://api.elsevier.com/content/search/scopus*' => Http::response([
            'search-results' => [
                'opensearch:totalResults' => '1',
                'entry' => [[
                    'eid' => '2-s2.0-auto',
                    'dc:title' => 'Automatically imported paper',
                ]],
            ],
        ]),
        'https://api.elsevier.com/content/abstract/eid/*' => Http::response([
            'abstracts-retrieval-response' => [
                'coredata' => ['dc:title' => 'Automatically imported paper'],
            ],
        ]),
    ]);

    $profile = ScopusProfile::query()->create(['author_id' => '57212345678']);
    app(ScopusIntegration::class)->discover($profile);
    $suggestion = ScopusSuggestion::query()->firstOrFail();

    expect($importer->count)->toBe(1)
        ->and($suggestion->status)->toBe(SuggestionStatus::Imported)
        ->and($suggestion->imported_model_id)->toBe(77)
        ->and($suggestion->resolved_at)->not->toBeNull();
});

it('records an automatic import failure without failing discovery', function (): void {
    config()->set('scopus-laravel.discovery.auto_import', true);

    app()->instance(SuggestionImporter::class, new class implements SuggestionImporter
    {
        public function import(ScopusSuggestion $suggestion): PublicationReference
        {
            throw new RuntimeException('Host publication validation failed.');
        }
    });

    Http::fake([
        'https://api.elsevier.com/content/search/scopus*' => Http::response([
            'search-results' => [
                'opensearch:totalResults' => '1',
                'entry' => [[
                    'eid' => '2-s2.0-import-error',
                    'dc:title' => 'Import failure paper',
                ]],
            ],
        ]),
        'https://api.elsevier.com/content/abstract/eid/*' => Http::response([
            'abstracts-retrieval-response' => [
                'coredata' => ['dc:title' => 'Import failure paper'],
            ],
        ]),
    ]);

    $profile = ScopusProfile::query()->create(['author_id' => '57212345678']);
    $result = app(ScopusIntegration::class)->discover($profile);
    $suggestion = ScopusSuggestion::query()->firstOrFail();

    expect($result->created)->toBe(1)
        ->and($suggestion->status)->toBe(SuggestionStatus::Failed)
        ->and($suggestion->match_reason)->toBe('Host publication validation failed.')
        ->and($profile->fresh()->sync_status)->toBe(SyncStatus::Success);
});

it('keeps an ignored suggestion resolved while refreshing its metadata', function (): void {
    Http::fake([
        'https://api.elsevier.com/content/search/scopus*' => Http::sequence()
            ->push([
                'search-results' => [
                    'opensearch:totalResults' => '1',
                    'entry' => [[
                        'eid' => '2-s2.0-ignore',
                        'dc:title' => 'Original title',
                        'citedby-count' => '2',
                    ]],
                ],
            ])
            ->push([
                'search-results' => [
                    'opensearch:totalResults' => '1',
                    'entry' => [[
                        'eid' => '2-s2.0-ignore',
                        'dc:title' => 'Corrected title',
                        'citedby-count' => '9',
                    ]],
                ],
            ]),
        'https://api.elsevier.com/content/abstract/eid/*' => Http::response([
            'abstracts-retrieval-response' => [
                'coredata' => ['dc:title' => 'Original title', 'citedby-count' => '2'],
            ],
        ]),
    ]);

    $profile = ScopusProfile::query()->create(['author_id' => '57212345678']);
    app(ScopusIntegration::class)->discover($profile);

    $suggestion = ScopusSuggestion::query()->firstOrFail();
    $resolvedAt = now()->subMinute();
    $suggestion->update([
        'status' => SuggestionStatus::Ignored,
        'resolved_at' => $resolvedAt,
    ]);

    app(ScopusIntegration::class)->discover($profile);
    $suggestion->refresh();

    expect($suggestion->status)->toBe(SuggestionStatus::Ignored)
        ->and($suggestion->title)->toBe('Corrected title')
        ->and($suggestion->citation_count)->toBe(9)
        ->and($suggestion->resolved_at->timestamp)->toBe($resolvedAt->timestamp);

    Http::assertSentCount(3); // two searches and only one abstract request
});

it('rolls back suggestion state when the host importer throws', function (): void {
    app()->instance(SuggestionImporter::class, new class implements SuggestionImporter
    {
        public function import(ScopusSuggestion $suggestion): PublicationReference
        {
            $suggestion->update(['match_reason' => 'temporary mutation']);

            throw new RuntimeException('Import failed.');
        }
    });

    $profile = ScopusProfile::query()->create(['author_id' => '57212345678']);
    $suggestion = ScopusSuggestion::query()->create([
        'scopus_profile_id' => $profile->id,
        'eid' => '2-s2.0-rollback',
        'title' => 'Rollback work',
        'mapped_type' => 'article',
        'status' => 'pending',
        'discovered_at' => now(),
    ]);

    expect(fn () => app(ImportSuggestion::class)->execute($suggestion))
        ->toThrow(RuntimeException::class, 'Import failed');

    expect($suggestion->fresh()->status)->toBe(SuggestionStatus::Pending)
        ->and($suggestion->fresh()->match_reason)->toBeNull()
        ->and($suggestion->fresh()->imported_model_id)->toBeNull();
});

it('resumes discovery from the last completed page after a retryable failure', function (): void {
    config()->set('scopus-laravel.discovery.page_size', 1);

    Http::fake([
        'https://api.elsevier.com/content/search/scopus*' => Http::sequence()
            ->push([
                'search-results' => [
                    'opensearch:totalResults' => '2',
                    'entry' => [['eid' => '2-s2.0-page-1', 'dc:title' => 'First page']],
                ],
            ])
            ->push([], 429, ['Retry-After' => '5'])
            ->push([
                'search-results' => [
                    'opensearch:totalResults' => '2',
                    'entry' => [['eid' => '2-s2.0-page-2', 'dc:title' => 'Second page']],
                ],
            ]),
        'https://api.elsevier.com/content/abstract/eid/*' => Http::sequence()
            ->push(['abstracts-retrieval-response' => ['coredata' => ['dc:title' => 'First page']]])
            ->push(['abstracts-retrieval-response' => ['coredata' => ['dc:title' => 'Second page']]]),
    ]);

    $profile = ScopusProfile::query()->create(['author_id' => '57212345678']);

    expect(fn () => app(ScopusIntegration::class)->discover($profile))
        ->toThrow(ScopusApiException::class);

    $profile->refresh();
    expect($profile->discovery_cursor)->toBe(1)
        ->and($profile->discovery_total)->toBe(2)
        ->and(ScopusSuggestion::query()->count())->toBe(1);

    $result = app(ScopusIntegration::class)->discover($profile->fresh());

    $profile->refresh();
    $searchStarts = collect(Http::recorded())
        ->map(fn (array $record): Request => $record[0])
        ->filter(fn (Request $request): bool => str_contains($request->url(), '/content/search/scopus'))
        ->map(function (Request $request): int {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return (int) ($query['start'] ?? 0);
        })
        ->values()
        ->all();

    expect($result->found)->toBe(1)
        ->and(ScopusSuggestion::query()->count())->toBe(2)
        ->and($profile->discovery_cursor)->toBeNull()
        ->and($profile->discovery_total)->toBeNull()
        ->and($searchStarts)->toBe([0, 1, 1]);
});

it('rejects an invalid host integration binding when the contract is resolved', function (): void {
    config()->set('scopus-laravel.integration.'.PublicationMatcher::class, stdClass::class);
    app()->forgetInstance(PublicationMatcher::class);

    expect(fn () => app(PublicationMatcher::class))
        ->toThrow(ScopusConfigurationException::class, 'Invalid Scopus integration binding');
});
