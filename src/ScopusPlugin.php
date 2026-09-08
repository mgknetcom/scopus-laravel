<?php

namespace Mgknetcom\Scopus;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Mgknetcom\Scopus\Filament\Resources\ScopusProfiles\ScopusProfileResource;
use Mgknetcom\Scopus\Filament\Resources\ScopusSuggestions\ScopusSuggestionResource;

final class ScopusPlugin implements Plugin
{
    public static function make(): self
    {
        return new self;
    }

    public function getId(): string
    {
        return 'scopus-laravel';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            ScopusProfileResource::class,
            ScopusSuggestionResource::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}
