<?php

namespace Mgknetcom\Scopus\Data;

final readonly class PublicationReference
{
    public function __construct(
        public string $modelType,
        public int|string $modelId,
        public ?string $reason = null,
    ) {}
}
