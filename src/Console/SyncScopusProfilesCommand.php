<?php

namespace Mgknetcom\Scopus\Console;

use Illuminate\Console\Command;
use Mgknetcom\Scopus\Jobs\SyncScopusProfile;
use Mgknetcom\Scopus\Models\ScopusProfile;

final class SyncScopusProfilesCommand extends Command
{
    protected $signature = 'scopus:profiles:sync
        {profile? : Scopus profile ID}
        {--stale=7 : Only profiles not synchronized in this many days}
        {--limit=100 : Maximum profiles to queue}';

    protected $description = 'Queue Scopus author metric synchronization';

    public function handle(): int
    {
        if (blank(config('scopus-laravel.api_key'))) {
            $this->error('SCOPUS_API_KEY is not configured.');

            return self::FAILURE;
        }

        $stale = filter_var($this->option('stale'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($stale === false || $limit === false) {
            $this->error('--stale must be non-negative and --limit must be positive.');

            return self::FAILURE;
        }

        $profileId = $this->argument('profile');
        $ids = ScopusProfile::query()
            ->when($profileId !== null, fn ($query) => $query->whereKey($profileId))
            ->when($profileId === null, fn ($query) => $query->where(
                fn ($query) => $query->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subDays($stale))
            ))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $ids->each(fn (int $id) => SyncScopusProfile::dispatch($id));
        $this->info('Queued '.$ids->count().' Scopus profile synchronizations.');

        return self::SUCCESS;
    }
}
