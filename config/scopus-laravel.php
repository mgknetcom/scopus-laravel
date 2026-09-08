<?php

use Mgknetcom\Scopus\Contracts\PublicationMatcher;
use Mgknetcom\Scopus\Contracts\SuggestionImporter;
use Mgknetcom\Scopus\Support\NullPublicationMatcher;
use Mgknetcom\Scopus\Support\NullSuggestionImporter;

return [
    'api_key' => env('SCOPUS_API_KEY'),
    'institution_token' => env('SCOPUS_INSTITUTION_TOKEN'),

    'endpoints' => [
        'author' => env('SCOPUS_AUTHOR_URL', 'https://api.elsevier.com/content/author/author_id'),
        'search' => env('SCOPUS_SEARCH_URL', 'https://api.elsevier.com/content/search/scopus'),
        'abstract' => env('SCOPUS_ABSTRACT_URL', 'https://api.elsevier.com/content/abstract/eid'),
    ],

    'http' => [
        'connect_timeout' => (int) env('SCOPUS_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('SCOPUS_TIMEOUT', 20),
        'user_agent' => env('SCOPUS_USER_AGENT', 'mgknetcom-scopus-laravel/1.0'),
    ],

    'discovery' => [
        // STANDARD view permits up to 200 results per request. The integration
        // fetches abstract metadata only for new, unmatched works.
        'page_size' => (int) env('SCOPUS_PAGE_SIZE', 200),
        'max_results' => (int) env('SCOPUS_MAX_RESULTS', 5000),
        'store_raw_payloads' => (bool) env('SCOPUS_STORE_RAW_PAYLOADS', false),
        // Enable only after binding SuggestionImporter.
        'auto_import' => (bool) env('SCOPUS_AUTO_IMPORT', false),
    ],

    'queue' => [
        'connection' => env('SCOPUS_QUEUE_CONNECTION'),
        'name' => env('SCOPUS_QUEUE', 'default'),
        'requests_per_minute' => (int) env('SCOPUS_REQUESTS_PER_MINUTE', 20),
        'max_retry_after' => (int) env('SCOPUS_MAX_RETRY_AFTER', 3600),
    ],

    'retention' => [
        'sync_runs_days' => (int) env('SCOPUS_SYNC_RUNS_RETENTION_DAYS', 90),
        'resolved_suggestions_days' => (int) env('SCOPUS_RESOLVED_RETENTION_DAYS', 365),
        'raw_payloads_days' => (int) env('SCOPUS_RAW_PAYLOAD_RETENTION_DAYS', 30),
    ],

    'integration' => [
        PublicationMatcher::class => NullPublicationMatcher::class,
        SuggestionImporter::class => NullSuggestionImporter::class,
    ],

    'filament' => [
        'navigation_group' => 'Scopus',
        'navigation_sort' => 80,
    ],
];
