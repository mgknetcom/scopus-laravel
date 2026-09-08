<?php

namespace Mgknetcom\Scopus\Filament\Resources\ScopusProfiles\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Mgknetcom\Scopus\Filament\Resources\ScopusProfiles\ScopusProfileResource;

final class EditScopusProfile extends EditRecord
{
    protected static string $resource = ScopusProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
