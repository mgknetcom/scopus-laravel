<?php

namespace Mgknetcom\Scopus\Contracts;

use Mgknetcom\Scopus\Data\PublicationReference;
use Mgknetcom\Scopus\Data\ScopusWork;
use Mgknetcom\Scopus\Models\ScopusProfile;

interface PublicationMatcher
{
    /** Match by DOI first, then by normalized exact title. */
    public function match(ScopusProfile $profile, ScopusWork $work): ?PublicationReference;
}
