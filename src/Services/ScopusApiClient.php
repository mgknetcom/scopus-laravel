<?php

namespace Mgknetcom\Scopus\Services;

use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mgknetcom\Scopus\Data\AuthorMetrics;
use Mgknetcom\Scopus\Data\ScopusWork;
use Mgknetcom\Scopus\Data\ScopusWorksPage;
use Mgknetcom\Scopus\Exceptions\ScopusApiException;
use Mgknetcom\Scopus\Exceptions\ScopusConfigurationException;

final class ScopusApiClient
{
    public function __construct(private readonly ScopusResponseMapper $mapper) {}

    public function fetchAuthorMetrics(string $authorId): AuthorMetrics
    {
        $response = $this->get(
            rtrim((string) config('scopus-laravel.endpoints.author'), '/').'/'.rawurlencode($this->authorId($authorId)),
        );

        return $this->mapper->authorMetrics($this->payload($response));
    }

    /** @return array<int, ScopusWork> */
    public function fetchWorksByAuthor(string $authorId): array
    {
        $works = [];

        foreach ($this->iterateWorksByAuthor($authorId) as $page) {
            foreach ($page->works as $work) {
                $works[$work->eid] = $work;
            }
        }

        return array_values($works);
    }

    /** @return Generator<int, ScopusWorksPage, mixed, void> */
    public function iterateWorksByAuthor(string $authorId, int $start = 0): Generator
    {
        $authorId = $this->authorId($authorId);
        $pageSize = max(1, min(200, (int) config('scopus-laravel.discovery.page_size', 200)));
        $maxResults = max(1, min(5000, (int) config('scopus-laravel.discovery.max_results', 5000)));
        $start = max(0, $start);

        while ($start < $maxResults) {
            $pageStart = $start;
            $count = min($pageSize, $maxResults - $pageStart);
            $response = $this->get((string) config('scopus-laravel.endpoints.search'), [
                'query' => 'AU-ID('.$authorId.')',
                'view' => 'STANDARD',
                'start' => $pageStart,
                'count' => $count,
                'sort' => '-coverDate',
                'suppressNavLinks' => 'true',
            ]);

            $payload = $this->payload($response);
            $entries = data_get($payload, 'search-results.entry', []);
            if (! is_array($entries)) {
                throw new ScopusApiException('Scopus returned an invalid publication list.');
            }

            $works = collect($entries)
                ->filter(fn (mixed $entry): bool => is_array($entry))
                ->map(fn (array $entry): ?ScopusWork => $this->mapper->searchEntry($entry))
                ->filter()
                ->keyBy(fn (ScopusWork $work): string => $work->eid)
                ->values()
                ->all();

            $total = min($maxResults, max(0, (int) data_get($payload, 'search-results.opensearch:totalResults', 0)));
            $start = $pageStart + count($entries);
            $complete = $entries === [] || $start >= $total || $start >= $maxResults;

            yield new ScopusWorksPage($works, $pageStart, $start, $total, $complete);

            if ($complete) {
                return;
            }
        }
    }

    public function fetchAbstract(ScopusWork $summary): ScopusWork
    {
        $response = $this->get(
            rtrim((string) config('scopus-laravel.endpoints.abstract'), '/').'/'.rawurlencode($summary->eid),
            ['view' => 'META'],
        );

        return $this->mapper->abstract($this->payload($response), $summary);
    }

    /** @param array<string, scalar> $query */
    private function get(string $url, array $query = []): Response
    {
        try {
            $response = $this->request()->get($url, $query);
        } catch (ConnectionException $exception) {
            throw new ScopusApiException('Unable to connect to Scopus.', true, previous: $exception);
        }

        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();
        $retryable = $status === 408 || $status === 429 || $status >= 500;
        $message = match ($status) {
            401, 403 => 'Scopus rejected the configured credentials or entitlement.',
            404 => 'The requested Scopus record was not found.',
            429 => 'Scopus request quota or rate limit was reached.',
            default => 'Scopus returned HTTP '.$status.'.',
        };

        throw new ScopusApiException($message, $retryable, $status, $this->retryAfter($response));
    }

    private function request(): PendingRequest
    {
        $apiKey = config('scopus-laravel.api_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new ScopusConfigurationException('SCOPUS_API_KEY is not configured.');
        }

        $headers = [
            'X-ELS-APIKey' => $apiKey,
            'X-ELS-ReqId' => (string) Str::uuid(),
        ];
        if (filled(config('scopus-laravel.institution_token'))) {
            $headers['X-ELS-Insttoken'] = (string) config('scopus-laravel.institution_token');
        }

        return Http::acceptJson()
            ->withHeaders($headers)
            ->withUserAgent((string) config('scopus-laravel.http.user_agent'))
            ->connectTimeout((int) config('scopus-laravel.http.connect_timeout', 5))
            ->timeout((int) config('scopus-laravel.http.timeout', 20));
    }

    /** @return array<string, mixed> */
    private function payload(Response $response): array
    {
        $payload = $response->json();
        if (! is_array($payload)) {
            throw new ScopusApiException('Scopus returned an invalid JSON response.');
        }

        return $payload;
    }

    private function authorId(string $authorId): string
    {
        $authorId = trim($authorId);
        if (preg_match('/^\d{10,20}$/', $authorId) !== 1) {
            throw new ScopusConfigurationException('Scopus Author ID must contain 10 to 20 digits.');
        }

        return $authorId;
    }

    private function retryAfter(Response $response): ?int
    {
        if ($response->status() !== 429) {
            return null;
        }

        $header = trim((string) $response->header('Retry-After'));
        if ($header === '') {
            return null;
        }

        $seconds = ctype_digit($header)
            ? (int) $header
            : (($timestamp = strtotime($header)) === false ? null : max(0, $timestamp - time()));

        return $seconds === null
            ? null
            : min($seconds, max(1, (int) config('scopus-laravel.queue.max_retry_after', 3600)));
    }
}
