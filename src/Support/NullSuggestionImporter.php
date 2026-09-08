<?php

namespace Mgknetcom\Scopus\Support;

use Mgknetcom\Scopus\Contracts\SuggestionImporter;
use Mgknetcom\Scopus\Data\PublicationReference;
use Mgknetcom\Scopus\Exceptions\ScopusConfigurationException;
use Mgknetcom\Scopus\Models\ScopusSuggestion;

final class NullSuggestionImporter implements SuggestionImporter
{
    public function import(ScopusSuggestion $suggestion): PublicationReference
    {
        throw new ScopusConfigurationException(
            'Bind '.SuggestionImporter::class.' to a host application importer before importing Scopus suggestions.'
        );
    }
}
