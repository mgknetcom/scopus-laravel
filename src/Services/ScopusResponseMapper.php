<?php

namespace Mgknetcom\Scopus\Services;

use Mgknetcom\Scopus\Data\AuthorMetrics;
use Mgknetcom\Scopus\Data\ScopusWork;
use Mgknetcom\Scopus\Enums\ScopusDocumentType;

final class ScopusResponseMapper
{
    /** @param array<string, mixed> $payload */
    public function authorMetrics(array $payload): AuthorMetrics
    {
        $profile = data_get($payload, 'author-retrieval-response.0')
            ?? data_get($payload, 'author-retrieval-response');
        $preferredName = data_get($profile, 'preferred-name')
            ?? data_get($profile, 'author-profile.preferred-name')
            ?? [];

        return new AuthorMetrics(
            citationCount: $this->nonNegativeInt(data_get($profile, 'coredata.citation-count')),
            documentCount: $this->nonNegativeInt(data_get($profile, 'coredata.document-count')),
            hIndex: $this->nullableNonNegativeInt(data_get($profile, 'h-index')),
            citedByCount: $this->nullableNonNegativeInt(data_get($profile, 'coredata.cited-by-count')),
            coauthorCount: $this->nullableNonNegativeInt(data_get($profile, 'coauthor-count')),
            givenName: $this->string(data_get($preferredName, 'given-name')),
            surname: $this->string(data_get($preferredName, 'surname')),
            indexedName: $this->string(data_get($preferredName, 'ce:indexed-name')),
            initials: $this->string(data_get($preferredName, 'initials')),
            orcid: $this->string(data_get($profile, 'orcid')),
        );
    }

    /** @param array<string, mixed> $entry */
    public function searchEntry(array $entry): ?ScopusWork
    {
        $eid = $this->string($entry['eid'] ?? null);
        $title = $this->string($entry['dc:title'] ?? null);

        if ($eid === null || $title === null) {
            return null;
        }

        $description = $this->string($entry['subtypeDescription'] ?? null);

        return new ScopusWork(
            eid: $eid,
            scopusId: $this->scopusId($entry['dc:identifier'] ?? null),
            title: $title,
            doi: $this->doi($entry['prism:doi'] ?? null),
            authors: $this->string($entry['dc:creator'] ?? null),
            documentType: $description,
            mappedType: ScopusDocumentType::fromDescription($description),
            year: $this->year($entry['prism:coverDate'] ?? null),
            isbn: $this->identifier($entry['prism:isbn'] ?? null),
            issn: $this->identifier($entry['prism:issn'] ?? null),
            pages: $this->string($entry['prism:pageRange'] ?? null),
            publisher: null,
            conference: null,
            sourceTitle: $this->string($entry['prism:publicationName'] ?? null),
            citationCount: $this->nonNegativeInt($entry['citedby-count'] ?? null),
            rawPayload: config('scopus-laravel.discovery.store_raw_payloads') ? $entry : null,
        );
    }

    /** @param array<string, mixed> $payload */
    public function abstract(array $payload, ScopusWork $summary): ScopusWork
    {
        $response = data_get($payload, 'abstracts-retrieval-response', $payload);
        $core = data_get($response, 'coredata', []);
        $source = (array) (data_get($response, 'item.bibrecord.head.source') ?? []);

        $description = $this->string(data_get($core, 'subtypeDescription')) ?? $summary->documentType;
        $mappedType = ScopusDocumentType::fromDescription($description);
        $authors = $this->authors(data_get($response, 'authors.author')) ?? $summary->authors;
        // Articles use the source title while other publication types prefer
        // the conference event name when one is available.
        $conference = $mappedType === ScopusDocumentType::Article
            ? ($summary->sourceTitle ?? $this->conferenceName($source))
            : ($this->conferenceName($source) ?? $summary->sourceTitle);

        return new ScopusWork(
            eid: $summary->eid,
            scopusId: $summary->scopusId ?? $this->scopusId(data_get($core, 'dc:identifier')),
            title: $this->string(data_get($core, 'dc:title')) ?? $summary->title,
            doi: $this->doi(data_get($core, 'prism:doi')) ?? $summary->doi,
            authors: $authors,
            documentType: $description,
            mappedType: $mappedType,
            year: $this->year(data_get($core, 'prism:coverDate'))
                ?? $this->year(data_get($source, 'publicationyear.@first'))
                ?? $this->year(data_get($source, 'publicationyear.@attributes.first'))
                ?? $summary->year,
            isbn: $this->identifier(data_get($source, 'isbn')) ?? $summary->isbn,
            issn: $this->identifier(data_get($core, 'prism:issn')) ?? $summary->issn,
            pages: $this->pages($source) ?? $summary->pages,
            publisher: $this->string(data_get($source, 'publisher.publishername')) ?? $summary->publisher,
            conference: $conference,
            sourceTitle: $this->string(data_get($core, 'prism:publicationName')) ?? $summary->sourceTitle,
            citationCount: max($summary->citationCount, $this->nonNegativeInt(data_get($core, 'citedby-count'))),
            rawPayload: config('scopus-laravel.discovery.store_raw_payloads') ? $payload : null,
        );
    }

    private function authors(mixed $authors): ?string
    {
        if (! is_array($authors)) {
            return null;
        }

        if (! array_is_list($authors)) {
            $authors = [$authors];
        }

        $names = collect($authors)->filter(fn (mixed $author): bool => is_array($author))
            ->map(function (array $author, int $index): ?string {
                $surname = $this->string($author['surname'] ?? null);
                $initials = $this->string($author['initials'] ?? null);
                $indexedName = $this->string($author['ce:indexed-name'] ?? null);

                if ($surname === null) {
                    return $indexedName;
                }

                // Keep the first author in surname-first bibliographic form.
                return $index === 0
                    ? trim($surname.', '.($initials ?? ''), ' ,')
                    : trim(($initials ? $initials.' ' : '').$surname);
            })
            ->filter()
            ->values();

        return $names->isEmpty() ? null : $names->implode(', ');
    }

    /** @param array<string, mixed> $source */
    private function conferenceName(array $source): ?string
    {
        return $this->string(data_get($source, 'additional-srcinfo.conferenceinfo.confevent.confname'));
    }

    /** @param array<string, mixed> $source */
    private function pages(array $source): ?string
    {
        $first = $this->string(data_get($source, 'volisspag.pagerange.@first'))
            ?? $this->string(data_get($source, 'volisspag.pagerange.@attributes.first'));
        $last = $this->string(data_get($source, 'volisspag.pagerange.@last'))
            ?? $this->string(data_get($source, 'volisspag.pagerange.@attributes.last'));

        return match (true) {
            $first !== null && $last !== null && $first !== $last => $first.'-'.$last,
            $first !== null => $first,
            default => null,
        };
    }

    private function identifier(mixed $value): ?string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                $value = $value[0] ?? null;
            }
            if (is_array($value)) {
                $value = $value['$'] ?? $value['@value'] ?? null;
            }
        }

        return $this->string($value);
    }

    private function doi(mixed $value): ?string
    {
        $value = $this->string($value);
        if ($value === null) {
            return null;
        }

        $value = preg_replace('#^https?://(?:dx\.)?doi\.org/#i', '', $value) ?? $value;

        return str_starts_with(mb_strtolower($value), '10.') ? mb_strtolower($value) : null;
    }

    private function scopusId(mixed $value): ?string
    {
        $value = $this->string($value);

        return $value === null ? null : preg_replace('/^SCOPUS_ID:/i', '', $value);
    }

    private function year(mixed $value): ?int
    {
        return preg_match('/(?:19|20)\d{2}/', (string) $value, $matches) === 1 ? (int) $matches[0] : null;
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nonNegativeInt(mixed $value): int
    {
        $value = is_string($value) ? str_replace(',', '', $value) : $value;

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function nullableNonNegativeInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : $this->nonNegativeInt($value);
    }
}
