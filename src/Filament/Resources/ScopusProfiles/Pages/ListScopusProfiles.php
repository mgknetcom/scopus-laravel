<?php

namespace Mgknetcom\Scopus\Filament\Resources\ScopusProfiles\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Mgknetcom\Scopus\Filament\Resources\ScopusProfiles\ScopusProfileResource;

final class ListScopusProfiles extends ListRecords
{
    protected static string $resource = ScopusProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
