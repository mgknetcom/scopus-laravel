<?php

namespace Mgknetcom\Scopus\Console;

use Illuminate\Console\Command;
use Mgknetcom\Scopus\Jobs\DiscoverScopusWorks;
use Mgknetcom\Scopus\Models\ScopusProfile;

final class DiscoverScopusWorksCommand extends Command
{
    protected $signature = 'scopus:works:discover
        {profile? : Scopus profile ID}
        {--limit=100 : Maximum profiles to queue}';

    protected $description = 'Queue Scopus publication discovery for profiles';

    public function handle(): int
    {
        if (blank(config('scopus-laravel.api_key'))) {
            $this->error('SCOPUS_API_KEY is not configured.');

            return self::FAILURE;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($limit === false) {
            $this->error('--limit must be positive.');

            return self::FAILURE;
        }

        $profileId = $this->argument('profile');
        $ids = ScopusProfile::query()
            ->when($profileId !== null, fn ($query) => $query->whereKey($profileId))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $ids->each(fn (int $id) => DiscoverScopusWorks::dispatch($id));
        $this->info('Queued '.$ids->count().' Scopus publication discoveries.');

        return self::SUCCESS;
    }
}
