<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mgknetcom\Scopus\Exceptions\ScopusApiException;
use Mgknetcom\Scopus\Jobs\DiscoverScopusWorks;
use Mgknetcom\Scopus\Jobs\SyncScopusProfile;
use Mgknetcom\Scopus\Models\ScopusProfile;
use Mgknetcom\Scopus\Models\ScopusSuggestion;
use Mgknetcom\Scopus\Models\ScopusSyncRun;
use Mgknetcom\Scopus\Services\ScopusIntegration;

it('queues only stale profiles for metric synchronization', function (): void {
    Bus::fake();

    $neverSynced = ScopusProfile::query()->create(['author_id' => '57212345670']);
    $stale = ScopusProfile::query()->create(['author_id' => '57212345671']);
    $stale->forceFill(['last_synced_at' => now()->subDays(10)])->save();
    $fresh = ScopusProfile::query()->create(['author_id' => '57212345672']);
    $fresh->forceFill(['last_synced_at' => now()->subDay()])->save();

    expect(Artisan::call('scopus:profiles:sync', ['--stale' => 7]))->toBe(0);

    Bus::assertDispatched(SyncScopusProfile::class, fn (SyncScopusProfile $job): bool => $job->profileId === $neverSynced->id);
    Bus::assertDispatched(SyncScopusProfile::class, fn (SyncScopusProfile $job): bool => $job->profileId === $stale->id);
    Bus::assertNotDispatched(SyncScopusProfile::class, fn (SyncScopusProfile $job): bool => $job->profileId === $fresh->id);
});

it('queues publication discovery for one selected profile', function (): void {
    Bus::fake();

    $selected = ScopusProfile::query()->create(['author_id' => '57212345673']);
    ScopusProfile::query()->create(['author_id' => '57212345674']);

    expect(Artisan::call('scopus:works:discover', ['profile' => $selected->id]))->toBe(0);

    Bus::assertDispatchedTimes(DiscoverScopusWorks::class, 1);
    Bus::assertDispatched(DiscoverScopusWorks::class, fn (DiscoverScopusWorks $job): bool => $job->profileId === $selected->id);
});

it('rejects invalid command options and missing credentials', function (): void {
    expect(Artisan::call('scopus:profiles:sync', ['--limit' => 0]))->toBe(1);

    expect(Artisan::call('scopus:works:discover', ['--limit' => 0]))->toBe(1);

    config()->set('scopus-laravel.api_key', null);

    expect(Artisan::call('scopus:works:discover'))->toBe(1)
        ->and(Artisan::call('scopus:profiles:sync'))->toBe(1);
});

it('uses configured prune retention values when command options are omitted', function (): void {
    config()->set('scopus-laravel.retention.sync_runs_days', 0);
    config()->set('scopus-laravel.retention.resolved_suggestions_days', 0);
    config()->set('scopus-laravel.retention.raw_payloads_days', 0);

    expect(Artisan::call('scopus:prune', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Would prune');
});

it('rejects negative prune retention values', function (): void {
    expect(Artisan::call('scopus:prune', ['--runs' => -1]))->toBe(1)
        ->and(Artisan::output())->toContain('non-negative integers');
});

it('executes profile synchronization jobs and safely ignores deleted profiles', function (): void {
    Http::fake([
        'https://api.elsevier.com/content/author/author_id/*' => Http::response([
            'author-retrieval-response' => [[
                'coredata' => ['citation-count' => '12', 'document-count' => '4'],
                'h-index' => '3',
            ]],
        ]),
    ]);

    $profile = ScopusProfile::query()->create(['author_id' => '57212345675']);
    $job = new SyncScopusProfile($profile->id);

    expect($job->uniqueId())->toBe((string) $profile->id)
        ->and($job->middleware())->toHaveCount(2);

    $job->handle(app(ScopusIntegration::class));

    expect($profile->fresh()->citation_count)->toBe(12);

    (new SyncScopusProfile(999999))->handle(app(ScopusIntegration::class));
    Http::assertSentCount(1);
});

it('releases a rate-limited job using the Retry-After delay', function (): void {
    Http::fake([
        'https://api.elsevier.com/content/author/author_id/*' => Http::response(
            ['error' => 'quota'],
            429,
            ['Retry-After' => '75'],
        ),
    ]);

    $profile = ScopusProfile::query()->create(['author_id' => '57212345679']);
    $job = (new SyncScopusProfile($profile->id))->withFakeQueueInteractions();

    $job->handle(app(ScopusIntegration::class));

    $job->assertReleased(delay: 75);
});

it('executes discovery jobs and safely ignores deleted profiles', function (): void {
    Http::fake([
        'https://api.elsevier.com/content/search/scopus*' => Http::response([
            'search-results' => ['opensearch:totalResults' => '0', 'entry' => []],
        ]),
    ]);

    $profile = ScopusProfile::query()->create(['author_id' => '57212345695']);
    $job = new DiscoverScopusWorks($profile->id);

    expect($job->uniqueId())->toBe((string) $profile->id)
        ->and($job->middleware())->toHaveCount(2);

    $job->handle(app(ScopusIntegration::class));
    (new DiscoverScopusWorks(999999))->handle(app(ScopusIntegration::class));

    Http::assertSentCount(1);
});

it('releases retryable discovery jobs using the provider delay', function (): void {
    Http::fake([
        'https://api.elsevier.com/content/search/scopus*' => Http::response([], 429, ['Retry-After' => '45']),
    ]);

    $profile = ScopusProfile::query()->create(['author_id' => '57212345696']);
    $job = (new DiscoverScopusWorks($profile->id))->withFakeQueueInteractions();

    $job->handle(app(ScopusIntegration::class));

    $job->assertReleased(delay: 45);
});

it('rethrows non-retryable job failures', function (): void {
    Http::fake(['*' => Http::response([], 403)]);

    $profile = ScopusProfile::query()->create(['author_id' => '57212345697']);

    expect(fn () => (new SyncScopusProfile($profile->id))->handle(app(ScopusIntegration::class)))
        ->toThrow(ScopusApiException::class, 'credentials')
        ->and(fn () => (new DiscoverScopusWorks($profile->id))->handle(app(ScopusIntegration::class)))
        ->toThrow(ScopusApiException::class, 'credentials');
});

it('reports prune candidates in dry-run mode without deleting them', function (): void {
    $profile = ScopusProfile::query()->create(['author_id' => '57212345676']);
    ScopusSyncRun::query()->create([
        'scopus_profile_id' => $profile->id,
        'operation' => 'profile',
        'status' => 'success',
        'started_at' => now()->subDays(100),
        'finished_at' => now()->subDays(100),
    ]);
    ScopusSuggestion::query()->create([
        'scopus_profile_id' => $profile->id,
        'eid' => '2-s2.0-prune-dry',
        'title' => 'Resolved work',
        'mapped_type' => 'other',
        'status' => 'ignored',
        'raw_payload' => ['stored' => true],
        'discovered_at' => now()->subDays(400),
        'resolved_at' => now()->subDays(400),
    ]);

    expect(Artisan::call('scopus:prune', [
        '--runs' => 90,
        '--resolved' => 365,
        '--raw' => 30,
        '--dry-run' => true,
    ]))->toBe(0)
        ->and(ScopusSyncRun::query()->count())->toBe(1)
        ->and(ScopusSuggestion::query()->count())->toBe(1)
        ->and(Artisan::output())->toContain('Would prune 1 sync runs');
});

it('prunes expired package data while keeping recent and pending records', function (): void {
    $profile = ScopusProfile::query()->create(['author_id' => '57212345677']);
    ScopusSyncRun::query()->create([
        'scopus_profile_id' => $profile->id,
        'operation' => 'profile',
        'status' => 'success',
        'started_at' => now()->subDays(100),
        'finished_at' => now()->subDays(100),
    ]);
    $pending = ScopusSuggestion::query()->create([
        'scopus_profile_id' => $profile->id,
        'eid' => '2-s2.0-pending',
        'title' => 'Pending work',
        'mapped_type' => 'other',
        'status' => 'pending',
        'raw_payload' => ['stored' => true],
        'discovered_at' => now()->subDays(40),
    ]);
    ScopusSuggestion::query()->create([
        'scopus_profile_id' => $profile->id,
        'eid' => '2-s2.0-resolved',
        'title' => 'Old resolved work',
        'mapped_type' => 'other',
        'status' => 'ignored',
        'discovered_at' => now()->subDays(400),
        'resolved_at' => now()->subDays(400),
    ]);

    expect(Artisan::call('scopus:prune', [
        '--runs' => 90,
        '--resolved' => 365,
        '--raw' => 30,
    ]))->toBe(0)
        ->and(ScopusSyncRun::query()->count())->toBe(0)
        ->and(ScopusSuggestion::query()->count())->toBe(1)
        ->and($pending->fresh()->raw_payload)->toBeNull();
});
