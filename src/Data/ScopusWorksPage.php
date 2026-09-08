<?php

namespace Mgknetcom\Scopus\Data;

final readonly class ScopusWorksPage
{
    /** @param array<int, ScopusWork> $works */
    public function __construct(
        public array $works,
        public int $start,
        public int $nextStart,
        public int $total,
        public bool $complete,
    ) {}
}
