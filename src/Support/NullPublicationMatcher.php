<?php

namespace Mgknetcom\Scopus\Support;

use Mgknetcom\Scopus\Contracts\PublicationMatcher;
use Mgknetcom\Scopus\Data\PublicationReference;
use Mgknetcom\Scopus\Data\ScopusWork;
use Mgknetcom\Scopus\Models\ScopusProfile;

final class NullPublicationMatcher implements PublicationMatcher
{
    public function match(ScopusProfile $profile, ScopusWork $work): ?PublicationReference
    {
        return null;
    }
}
