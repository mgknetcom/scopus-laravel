<?php

namespace Mgknetcom\Scopus;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Mgknetcom\Scopus\Console\DiscoverScopusWorksCommand;
use Mgknetcom\Scopus\Console\PruneScopusDataCommand;
use Mgknetcom\Scopus\Console\SyncScopusProfilesCommand;
use Mgknetcom\Scopus\Contracts\PublicationMatcher;
use Mgknetcom\Scopus\Contracts\SuggestionImporter;
use Mgknetcom\Scopus\Exceptions\ScopusConfigurationException;

final class ScopusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/scopus-laravel.php', 'scopus-laravel');

        foreach ([PublicationMatcher::class, SuggestionImporter::class] as $contract) {
            $this->app->bind($contract, function ($app) use ($contract): object {
                $implementation = config('scopus-laravel.integration.'.$contract);
                if (! is_string($implementation) || ! is_a($implementation, $contract, true)) {
                    throw new ScopusConfigurationException('Invalid Scopus integration binding for '.$contract.'.');
                }

                return $app->make($implementation);
            });
        }
    }

    public function boot(): void
    {
        $migration = __DIR__.'/../database/migrations/create_scopus_laravel_tables.php.stub';

        $this->publishes([
            __DIR__.'/../config/scopus-laravel.php' => config_path('scopus-laravel.php'),
        ], 'scopus-laravel-config');

        $this->publishesMigrations(
            [$migration => database_path('migrations/'.date('Y_m_d_His').'_create_scopus_laravel_tables.php')],
            'scopus-laravel-migrations',
        );

        RateLimiter::for('scopus-laravel', fn () => Limit::perMinute(
            max(1, (int) config('scopus-laravel.queue.requests_per_minute', 20))
        )->by('scopus-laravel'));

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncScopusProfilesCommand::class,
                DiscoverScopusWorksCommand::class,
                PruneScopusDataCommand::class,
            ]);
        }
    }
}
