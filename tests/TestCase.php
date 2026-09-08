<?php

namespace Mgknetcom\Scopus\Tests;

use Filament\Facades\Filament;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;
use Mgknetcom\Scopus\ScopusServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        // Filament ships as several composer packages (filament/forms, filament/tables,
        // livewire/livewire, ...), each with its own auto-discovered provider. A host
        // Laravel application discovers these automatically; Testbench's sandboxed test
        // app does not, since this package ships no workbench skeleton. Read the
        // manifest from this package's own vendor directory instead of hand-listing
        // every transitive provider. The cache file is written outside the package
        // directory so running the suite never leaves generated artifacts behind.
        $manifest = new PackageManifest(
            new Filesystem,
            dirname(__DIR__),
            sys_get_temp_dir().'/scopus-laravel-test-packages.php',
        );

        return array_merge($manifest->providers(), [ScopusServiceProvider::class, TestPanelProvider::class]);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('scopus-laravel.api_key', 'test-key');
        $app['config']->set('scopus-laravel.discovery.page_size', 200);
        $app['config']->set('scopus-laravel.discovery.max_results', 5000);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // The distributable migration is a .stub until it is published.
        $migration = require __DIR__.'/../database/migrations/create_scopus_laravel_tables.php.stub';
        $migration->up();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('scopus-test');
    }
}
