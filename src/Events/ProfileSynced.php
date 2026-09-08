<?php

namespace Mgknetcom\Scopus\Events;

use Illuminate\Queue\SerializesModels;
use Mgknetcom\Scopus\Data\AuthorMetrics;
use Mgknetcom\Scopus\Models\ScopusProfile;
use Mgknetcom\Scopus\Models\ScopusSyncRun;

final readonly class ProfileSynced
{
    use SerializesModels;

    public function __construct(
        public ScopusProfile $profile,
        public AuthorMetrics $metrics,
        public ScopusSyncRun $run,
    ) {}
}
