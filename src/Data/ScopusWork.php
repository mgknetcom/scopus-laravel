<?php

namespace Mgknetcom\Scopus\Data;

use Mgknetcom\Scopus\Enums\ScopusDocumentType;

final readonly class ScopusWork
{
    /** @param array<string, mixed>|null $rawPayload */
    public function __construct(
        public string $eid,
        public ?string $scopusId,
        public string $title,
        public ?string $doi,
        public ?string $authors,
        public ?string $documentType,
        public ScopusDocumentType $mappedType,
        public ?int $year,
        public ?string $isbn,
        public ?string $issn,
        public ?string $pages,
        public ?string $publisher,
        public ?string $conference,
        public ?string $sourceTitle,
        public int $citationCount,
        public ?array $rawPayload = null,
    ) {}

    /** @return array<string, mixed> */
    public function suggestionAttributes(): array
    {
        return [
            'eid' => $this->eid,
            'scopus_id' => $this->scopusId,
            'doi' => $this->doi,
            'title' => $this->title,
            'authors' => $this->authors,
            'document_type' => $this->documentType,
            'mapped_type' => $this->mappedType->value,
            'year' => $this->year,
            'isbn' => $this->isbn,
            'issn' => $this->issn,
            'pages' => $this->pages,
            'publisher' => $this->publisher,
            'conference' => $this->conference,
            'source_title' => $this->sourceTitle,
            'citation_count' => $this->citationCount,
            'raw_payload' => $this->rawPayload,
        ];
    }
}
