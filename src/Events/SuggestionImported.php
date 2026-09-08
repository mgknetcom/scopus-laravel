<?php

namespace Mgknetcom\Scopus\Events;

use Illuminate\Queue\SerializesModels;
use Mgknetcom\Scopus\Data\PublicationReference;
use Mgknetcom\Scopus\Models\ScopusSuggestion;

final readonly class SuggestionImported
{
    use SerializesModels;

    public function __construct(
        public ScopusSuggestion $suggestion,
        public PublicationReference $reference,
    ) {}
}
