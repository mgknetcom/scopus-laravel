<?php

use Mgknetcom\Scopus\Data\ScopusWork;
use Mgknetcom\Scopus\Enums\ScopusDocumentType;
use Mgknetcom\Scopus\Services\ScopusResponseMapper;

it('maps document types and complete author metrics', function (): void {
    $mapper = new ScopusResponseMapper;

    $metrics = $mapper->authorMetrics([
        'author-retrieval-response' => [[
            'coredata' => [
                'citation-count' => '1,234',
                'document-count' => '42',
                'cited-by-count' => '29',
            ],
            'h-index' => '17',
            'coauthor-count' => '31',
            'preferred-name' => [
                'given-name' => 'Ivan',
                'surname' => 'Ivanov',
                'ce:indexed-name' => 'Ivanov I.',
                'initials' => 'I.',
            ],
            'orcid' => '0000-0001-2345-6789',
        ]],
    ]);

    expect($metrics->citationCount)->toBe(1234)
        ->and($metrics->documentCount)->toBe(42)
        ->and($metrics->hIndex)->toBe(17)
        ->and($metrics->citedByCount)->toBe(29)
        ->and($metrics->coauthorCount)->toBe(31)
        ->and($metrics->givenName)->toBe('Ivan')
        ->and($metrics->surname)->toBe('Ivanov')
        ->and($metrics->indexedName)->toBe('Ivanov I.')
        ->and($metrics->initials)->toBe('I.')
        ->and($metrics->orcid)->toBe('0000-0001-2345-6789')
        ->and(ScopusDocumentType::fromDescription('Article'))->toBe(ScopusDocumentType::Article)
        ->and(ScopusDocumentType::fromDescription('Conference Paper'))->toBe(ScopusDocumentType::ConferencePaper)
        ->and(ScopusDocumentType::fromDescription('Book'))->toBe(ScopusDocumentType::Book)
        ->and(ScopusDocumentType::fromDescription('Book Chapter'))->toBe(ScopusDocumentType::BookChapter)
        ->and(ScopusDocumentType::fromDescription('Editorial'))->toBe(ScopusDocumentType::Other);
});

it('maps search and abstract metadata defensively', function (): void {
    $mapper = new ScopusResponseMapper;
    $summary = $mapper->searchEntry([
        'eid' => '2-s2.0-123',
        'dc:identifier' => 'SCOPUS_ID:123',
        'dc:title' => 'A useful paper',
        'dc:creator' => 'Ivanov, I.',
        'prism:doi' => 'https://doi.org/10.1000/Test',
        'prism:coverDate' => '2025-03-01',
        'prism:publicationName' => 'Proceedings of Science',
        'citedby-count' => '9',
        'subtypeDescription' => 'Conference Paper',
    ]);

    expect($summary)->toBeInstanceOf(ScopusWork::class)
        ->and($summary->doi)->toBe('10.1000/test')
        ->and($summary->year)->toBe(2025)
        ->and($summary->mappedType)->toBe(ScopusDocumentType::ConferencePaper);

    $work = $mapper->abstract([
        'abstracts-retrieval-response' => [
            'coredata' => [
                'dc:title' => 'A useful paper',
                'prism:doi' => '10.1000/Test',
                'prism:coverDate' => '2025-03-01',
                'citedby-count' => '10',
                'subtypeDescription' => 'Conference Paper',
            ],
            'authors' => ['author' => [
                ['surname' => 'Ivanov', 'initials' => 'I.'],
                ['surname' => 'Petrova', 'initials' => 'P.'],
            ]],
            'item' => ['bibrecord' => ['head' => ['source' => [
                'isbn' => ['$' => '978-1-2345-6789-0'],
                'publicationyear' => ['@first' => '2025'],
                'volisspag' => ['pagerange' => ['@first' => '12', '@last' => '19']],
                'publisher' => ['publishername' => 'Academic Press'],
                'additional-srcinfo' => ['conferenceinfo' => ['confevent' => ['confname' => 'Science 2025']]],
            ]]]],
        ],
    ], $summary);

    expect($work->authors)->toBe('Ivanov, I., P. Petrova')
        ->and($work->pages)->toBe('12-19')
        ->and($work->publisher)->toBe('Academic Press')
        ->and($work->conference)->toBe('Science 2025')
        ->and($work->citationCount)->toBe(10);
});

it('rejects search entries without a stable EID or title', function (): void {
    $mapper = new ScopusResponseMapper;

    expect($mapper->searchEntry(['dc:title' => 'Missing EID']))->toBeNull()
        ->and($mapper->searchEntry(['eid' => '2-s2.0-123']))->toBeNull();
});

it('normalizes malformed and optional scalar values safely', function (): void {
    $mapper = new ScopusResponseMapper;
    $work = $mapper->searchEntry([
        'eid' => ' 2-s2.0-456 ',
        'dc:identifier' => 'SCOPUS_ID:456',
        'dc:title' => '  Defensive mapping  ',
        'prism:doi' => 'not-a-doi',
        'prism:isbn' => [['$' => '9781234567890']],
        'prism:issn' => ['@value' => '1234-5678'],
        'prism:coverDate' => 'Published in 2023',
        'citedby-count' => '-12',
    ]);

    expect($work)->not->toBeNull()
        ->and($work->eid)->toBe('2-s2.0-456')
        ->and($work->scopusId)->toBe('456')
        ->and($work->title)->toBe('Defensive mapping')
        ->and($work->doi)->toBeNull()
        ->and($work->isbn)->toBe('9781234567890')
        ->and($work->issn)->toBe('1234-5678')
        ->and($work->year)->toBe(2023)
        ->and($work->citationCount)->toBe(0);
});

it('falls back to search metadata when abstract fields are missing', function (): void {
    $mapper = new ScopusResponseMapper;
    $summary = new ScopusWork(
        eid: '2-s2.0-789',
        scopusId: '789',
        title: 'Search title',
        doi: '10.1000/search',
        authors: 'Search Author',
        documentType: 'Book Chapter',
        mappedType: ScopusDocumentType::BookChapter,
        year: 2022,
        isbn: '9781234567890',
        issn: null,
        pages: '10-20',
        publisher: 'Search Publisher',
        conference: null,
        sourceTitle: 'Search Source',
        citationCount: 12,
        rawPayload: null,
    );

    $work = $mapper->abstract([
        'abstracts-retrieval-response' => [
            'coredata' => ['citedby-count' => '4'],
            'authors' => ['author' => ['ce:indexed-name' => 'Indexed, Author']],
            'item' => ['bibrecord' => ['head' => ['source' => []]]],
        ],
    ], $summary);

    expect($work->title)->toBe('Search title')
        ->and($work->doi)->toBe('10.1000/search')
        ->and($work->authors)->toBe('Indexed, Author')
        ->and($work->year)->toBe(2022)
        ->and($work->pages)->toBe('10-20')
        ->and($work->publisher)->toBe('Search Publisher')
        ->and($work->conference)->toBe('Search Source')
        ->and($work->citationCount)->toBe(12);
});

it('handles an explicit null bibrecord source without crashing', function (): void {
    $mapper = new ScopusResponseMapper;
    $summary = new ScopusWork(
        eid: '2-s2.0-null-source',
        scopusId: null,
        title: 'Sparse record',
        doi: null,
        authors: null,
        documentType: null,
        mappedType: ScopusDocumentType::Other,
        year: null,
        isbn: null,
        issn: null,
        pages: null,
        publisher: null,
        conference: null,
        sourceTitle: null,
        citationCount: 0,
        rawPayload: null,
    );

    $work = $mapper->abstract([
        'abstracts-retrieval-response' => [
            'coredata' => ['dc:title' => 'Sparse record'],
            'item' => ['bibrecord' => ['head' => ['source' => null]]],
        ],
    ], $summary);

    expect($work->title)->toBe('Sparse record')
        ->and($work->pages)->toBeNull()
        ->and($work->conference)->toBeNull()
        ->and($work->publisher)->toBeNull();
});

it('stores raw API payloads only when explicitly enabled', function (): void {
    $mapper = new ScopusResponseMapper;
    $entry = ['eid' => '2-s2.0-raw', 'dc:title' => 'Raw payload'];

    config()->set('scopus-laravel.discovery.store_raw_payloads', false);
    $withoutRawPayload = $mapper->searchEntry($entry);

    config()->set('scopus-laravel.discovery.store_raw_payloads', true);
    $withRawPayload = $mapper->searchEntry($entry);

    expect($withoutRawPayload->rawPayload)->toBeNull()
        ->and($withRawPayload->rawPayload)->toBe($entry);
});

it('maps absent author metrics to safe defaults', function (): void {
    $metrics = (new ScopusResponseMapper)->authorMetrics([]);

    expect($metrics->citationCount)->toBe(0)
        ->and($metrics->documentCount)->toBe(0)
        ->and($metrics->hIndex)->toBeNull();
});
