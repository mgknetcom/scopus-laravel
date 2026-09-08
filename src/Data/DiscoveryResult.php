<?php

namespace Mgknetcom\Scopus\Data;

final readonly class DiscoveryResult
{
    public function __construct(
        public int $found,
        public int $created,
        public int $updated,
        public int $matched,
    ) {}
}
