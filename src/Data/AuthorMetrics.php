<?php

namespace Mgknetcom\Scopus\Data;

final readonly class AuthorMetrics
{
    public function __construct(
        public int $citationCount,
        public int $documentCount,
        public ?int $hIndex,
        public ?int $citedByCount,
        public ?int $coauthorCount,
        public ?string $givenName,
        public ?string $surname,
        public ?string $indexedName,
        public ?string $initials,
        public ?string $orcid,
    ) {}
}
