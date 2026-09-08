<?php

namespace Mgknetcom\Scopus\Tests;

use Filament\Panel;
use Filament\PanelProvider;
use Mgknetcom\Scopus\ScopusPlugin;

final class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('scopus-test')
            ->path('scopus-test')
            ->plugin(ScopusPlugin::make());
    }
}
