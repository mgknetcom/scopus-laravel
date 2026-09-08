<?php

namespace Mgknetcom\Scopus\Events;

use Illuminate\Queue\SerializesModels;
use Mgknetcom\Scopus\Models\ScopusProfile;
use Mgknetcom\Scopus\Models\ScopusSuggestion;

final readonly class WorkDiscovered
{
    use SerializesModels;

    public function __construct(
        public ScopusProfile $profile,
        public ScopusSuggestion $suggestion,
        public bool $created,
    ) {}
}
