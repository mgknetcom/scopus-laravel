<?php

namespace Mgknetcom\Scopus\Filament\Resources\ScopusSuggestions\Pages;

use Filament\Resources\Pages\ListRecords;
use Mgknetcom\Scopus\Filament\Resources\ScopusSuggestions\ScopusSuggestionResource;

final class ListScopusSuggestions extends ListRecords
{
    protected static string $resource = ScopusSuggestionResource::class;
}
