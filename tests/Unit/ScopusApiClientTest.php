<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mgknetcom\Scopus\Exceptions\ScopusApiException;
use Mgknetcom\Scopus\Exceptions\ScopusConfigurationException;
use Mgknetcom\Scopus\Services\ScopusApiClient;

it('paginates author works and removes duplicate EIDs', function (): void {
    config()->set('scopus-laravel.discovery.page_size', 2);
    config()->set('scopus-laravel.discovery.max_results', 4);

    $starts = [];
    Http::fake(function (Request $request) use (&$starts) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $start = (int) ($query['start'] ?? 0);
        $starts[] = $start;

        $entries = $start === 0
            ? [
                ['eid' => '2-s2.0-1', 'dc:title' => 'First'],
                ['eid' => '2-s2.0-2', 'dc:title' => 'Second'],
            ]
            : [
                ['eid' => '2-s2.0-2', 'dc:title' => 'Second, updated'],
                ['eid' => '2-s2.0-3', 'dc:title' => 'Third'],
            ];

        return Http::response([
            'search-results' => [
                'opensearch:totalResults' => '4',
                'entry' => $entries,
            ],
        ]);
    });

    $works = app(ScopusApiClient::class)->fetchWorksByAuthor('57212345678');

    expect($starts)->toBe([0, 2])
        ->and($works)->toHaveCount(3)
        ->and(array_column($works, 'eid'))->toBe(['2-s2.0-1', '2-s2.0-2', '2-s2.0-3'])
        ->and($works[1]->title)->toBe('Second, updated');
});

it('stops discovery at the configured maximum result count', function (): void {
    config()->set('scopus-laravel.discovery.page_size', 2);
    config()->set('scopus-laravel.discovery.max_results', 3);

    $requestedCounts = [];
    Http::fake(function (Request $request) use (&$requestedCounts) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $requestedCounts[] = (int) ($query['count'] ?? 0);
        $start = (int) ($query['start'] ?? 0);

        return Http::response([
            'search-results' => [
                'opensearch:totalResults' => '100',
                'entry' => $start === 0
                    ? [
                        ['eid' => '2-s2.0-1', 'dc:title' => 'First'],
                        ['eid' => '2-s2.0-2', 'dc:title' => 'Second'],
                    ]
                    : [['eid' => '2-s2.0-3', 'dc:title' => 'Third']],
            ],
        ]);
    });

    $works = app(ScopusApiClient::class)->fetchWorksByAuthor('57212345678');

    expect($requestedCounts)->toBe([2, 1])
        ->and($works)->toHaveCount(3);

    Http::assertSentCount(2);
});

it('sends the configured credentials and request headers', function (): void {
    config()->set('scopus-laravel.institution_token', 'institution-token');

    Http::fake([
        '*' => Http::response([
            'author-retrieval-response' => [[
                'coredata' => ['citation-count' => '0', 'document-count' => '0'],
            ]],
        ]),
    ]);

    app(ScopusApiClient::class)->fetchAuthorMetrics('57212345678');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-ELS-APIKey', 'test-key')
        && $request->hasHeader('X-ELS-Insttoken', 'institution-token')
        && $request->hasHeader('X-ELS-ReqId')
        && $request->hasHeader('User-Agent')
    );
});

it('rejects invalid author IDs before making an HTTP request', function (string $authorId): void {
    Http::fake();

    expect(fn () => app(ScopusApiClient::class)->fetchAuthorMetrics($authorId))
        ->toThrow(ScopusConfigurationException::class, 'must contain 10 to 20 digits');

    Http::assertNothingSent();
})->with(['', '123', '5721234567x', '5721-234-5678']);

it('requires an API key before making an HTTP request', function (): void {
    config()->set('scopus-laravel.api_key', '  ');
    Http::fake();

    expect(fn () => app(ScopusApiClient::class)->fetchAuthorMetrics('57212345678'))
        ->toThrow(ScopusConfigurationException::class, 'SCOPUS_API_KEY is not configured');

    Http::assertNothingSent();
});

it('marks rate limit responses as retryable and honors Retry-After', function (): void {
    Http::fake(['*' => Http::response(['error' => 'quota'], 429, ['Retry-After' => '120'])]);

    try {
        app(ScopusApiClient::class)->fetchAuthorMetrics('57212345678');
        throw new RuntimeException('Expected a ScopusApiException.');
    } catch (ScopusApiException $exception) {
        expect($exception->retryable)->toBeTrue()
            ->and($exception->httpStatus)->toBe(429)
            ->and($exception->retryAfterSeconds)->toBe(120)
            ->and($exception->getMessage())->toContain('rate limit');
    }
});

it('marks credential errors as non-retryable API errors', function (): void {
    Http::fake(['*' => Http::response(['error' => 'forbidden'], 403)]);

    try {
        app(ScopusApiClient::class)->fetchAuthorMetrics('57212345678');
        throw new RuntimeException('Expected a ScopusApiException.');
    } catch (ScopusApiException $exception) {
        expect($exception->retryable)->toBeFalse()
            ->and($exception->httpStatus)->toBe(403)
            ->and($exception->getMessage())->toContain('credentials');
    }
});

it('rejects successful responses that do not contain JSON objects', function (): void {
    Http::fake(['*' => Http::response('not-json', 200, ['Content-Type' => 'application/json'])]);

    expect(fn () => app(ScopusApiClient::class)->fetchAuthorMetrics('57212345678'))
        ->toThrow(ScopusApiException::class, 'invalid JSON response');
});

it('rejects invalid publication lists returned by Scopus', function (): void {
    Http::fake(['*' => Http::response([
        'search-results' => [
            'opensearch:totalResults' => '1',
            'entry' => 'invalid',
        ],
    ])]);

    expect(fn () => app(ScopusApiClient::class)->fetchWorksByAuthor('57212345678'))
        ->toThrow(ScopusApiException::class, 'invalid publication list');
});

it('converts connection failures into retryable API errors without leaking details', function (): void {
    Http::fake(fn () => throw new ConnectionException('private network details'));

    try {
        app(ScopusApiClient::class)->fetchAuthorMetrics('57212345678');
        throw new RuntimeException('Expected a ScopusApiException.');
    } catch (ScopusApiException $exception) {
        expect($exception->retryable)->toBeTrue()
            ->and($exception->getMessage())->toBe('Unable to connect to Scopus.')
            ->and($exception->getMessage())->not->toContain('private network details');
    }
});

it('reports unexpected HTTP statuses as retryable only for server errors', function (int $status, bool $retryable): void {
    Http::fake(['*' => Http::response([], $status)]);

    try {
        app(ScopusApiClient::class)->fetchAuthorMetrics('57212345678');
        throw new RuntimeException('Expected a ScopusApiException.');
    } catch (ScopusApiException $exception) {
        expect($exception->httpStatus)->toBe($status)
            ->and($exception->retryable)->toBe($retryable)
            ->and($exception->getMessage())->toContain("HTTP {$status}");
    }
})->with([
    'client response' => [418, false],
    'server response' => [503, true],
]);

it('accepts HTTP-date Retry-After values and caps their delay', function (): void {
    config()->set('scopus-laravel.queue.max_retry_after', 30);
    Http::fake(['*' => Http::response([], 429, [
        'Retry-After' => now()->addMinutes(2)->toRfc7231String(),
    ])]);

    try {
        app(ScopusApiClient::class)->fetchAuthorMetrics('57212345678');
        throw new RuntimeException('Expected a ScopusApiException.');
    } catch (ScopusApiException $exception) {
        expect($exception->retryAfterSeconds)->toBe(30);
    }
});

it('ignores malformed Retry-After values', function (): void {
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => 'not-a-date'])]);

    try {
        app(ScopusApiClient::class)->fetchAuthorMetrics('57212345678');
        throw new RuntimeException('Expected a ScopusApiException.');
    } catch (ScopusApiException $exception) {
        expect($exception->retryAfterSeconds)->toBeNull();
    }
});
