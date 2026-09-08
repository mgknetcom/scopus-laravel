<?php

namespace Mgknetcom\Scopus\Contracts;

use Mgknetcom\Scopus\Data\PublicationReference;
use Mgknetcom\Scopus\Models\ScopusSuggestion;

interface SuggestionImporter
{
    /**
     * Import the suggestion into the host application and link it to the owner
     * as a pending authorship suggestion.
     */
    public function import(ScopusSuggestion $suggestion): PublicationReference;
}
