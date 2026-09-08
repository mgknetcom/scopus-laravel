<?php

namespace Mgknetcom\Scopus\Events;

use Illuminate\Queue\SerializesModels;
use Mgknetcom\Scopus\Models\ScopusProfile;
use Mgknetcom\Scopus\Models\ScopusSyncRun;
use Throwable;

final readonly class SyncFailed
{
    use SerializesModels;

    public function __construct(
        public ScopusProfile $profile,
        public ScopusSyncRun $run,
        public Throwable $exception,
    ) {}
}
