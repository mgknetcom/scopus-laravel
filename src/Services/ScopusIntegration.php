<?php

namespace Mgknetcom\Scopus\Services;

use Illuminate\Support\Facades\DB;
use Mgknetcom\Scopus\Actions\ImportSuggestion;
use Mgknetcom\Scopus\Contracts\PublicationMatcher;
use Mgknetcom\Scopus\Data\DiscoveryResult;
use Mgknetcom\Scopus\Enums\SuggestionStatus;
use Mgknetcom\Scopus\Enums\SyncStatus;
use Mgknetcom\Scopus\Events\ProfileSynced;
use Mgknetcom\Scopus\Events\SyncFailed;
use Mgknetcom\Scopus\Events\WorkDiscovered;
use Mgknetcom\Scopus\Exceptions\ScopusApiException;
use Mgknetcom\Scopus\Models\ScopusProfile;
use Mgknetcom\Scopus\Models\ScopusSuggestion;
use Mgknetcom\Scopus\Models\ScopusSyncRun;
use Throwable;

final class ScopusIntegration
{
    public function __construct(
        private readonly ScopusApiClient $client,
        private readonly PublicationMatcher $matcher,
        private readonly ImportSuggestion $importSuggestion,
    ) {}

    public function syncProfile(ScopusProfile $profile): void
    {
        $run = $this->startRun($profile, 'profile');
        $profile->forceFill([
            'sync_status' => SyncStatus::Running,
            'sync_error' => null,
            'last_attempt_at' => now(),
        ])->save();

        try {
            $metrics = $this->client->fetchAuthorMetrics($profile->author_id);
            $profile->forceFill([
                'citation_count' => $metrics->citationCount,
                'document_count' => $metrics->documentCount,
                'h_index' => $metrics->hIndex,
                'cited_by_count' => $metrics->citedByCount,
                'coauthor_count' => $metrics->coauthorCount,
                'given_name' => $metrics->givenName,
                'surname' => $metrics->surname,
                'indexed_name' => $metrics->indexedName,
                'initials' => $metrics->initials,
                'orcid' => $metrics->orcid,
                'sync_status' => SyncStatus::Success,
                'sync_error' => null,
                'last_synced_at' => now(),
            ])->save();

            $this->finishRun($run, SyncStatus::Success);
            $profile->refresh();
            $run->refresh();
            event(new ProfileSynced($profile, $metrics, $run));
        } catch (Throwable $exception) {
            $this->fail($profile, $run, $exception);
            throw $exception;
        }
    }

    public function discover(ScopusProfile $profile): DiscoveryResult
    {
        $run = $this->startRun($profile, 'discovery');
        $profile->forceFill([
            'sync_status' => SyncStatus::Running,
            'sync_error' => null,
            'last_attempt_at' => now(),
        ])->save();

        $found = $created = $updated = $matched = 0;

        try {
            $cursor = (int) ($profile->discovery_cursor ?? 0);

            foreach ($this->client->iterateWorksByAuthor($profile->author_id, $cursor) as $page) {
                foreach ($page->works as $summary) {
                    $found++;
                    $existing = ScopusSuggestion::query()
                        ->where('scopus_profile_id', $profile->id)
                        ->where('eid', $summary->eid)
                        ->first();

                    $reference = $this->matcher->match($profile, $summary);
                    if ($reference !== null) {
                        $suggestion = ScopusSuggestion::query()->updateOrCreate(
                            ['scopus_profile_id' => $profile->id, 'eid' => $summary->eid],
                            $summary->suggestionAttributes() + [
                                'status' => SuggestionStatus::Matched,
                                'match_reason' => $reference->reason ?? 'Matched by host application',
                                'imported_model_type' => $reference->modelType,
                                'imported_model_id' => $reference->modelId,
                                'discovered_at' => $existing->discovered_at ?? now(),
                                'resolved_at' => now(),
                            ],
                        );
                        $matched++;
                        event(new WorkDiscovered($profile, $suggestion, $existing === null));

                        continue;
                    }

                    try {
                        // Only ever reached with $existing === null: an existing suggestion
                        // reuses the cheap search summary below and never calls the API here.
                        $work = $existing === null ? $this->client->fetchAbstract($summary) : $summary;
                    } catch (ScopusApiException $exception) {
                        if ($exception->retryable) {
                            throw $exception;
                        }

                        $suggestion = ScopusSuggestion::query()->updateOrCreate(
                            ['scopus_profile_id' => $profile->id, 'eid' => $summary->eid],
                            $summary->suggestionAttributes() + [
                                'status' => SuggestionStatus::Failed,
                                'match_reason' => str($exception->getMessage())->limit(255, '')->toString(),
                                'discovered_at' => now(),
                                'resolved_at' => now(),
                            ],
                        );
                        $created++;
                        event(new WorkDiscovered($profile, $suggestion, true));

                        continue;
                    }

                    $attributes = $work->suggestionAttributes() + [
                        'discovered_at' => $existing->discovered_at ?? now(),
                    ];

                    // A later sync updates metadata without reopening a resolved suggestion.
                    if ($existing === null) {
                        $attributes['status'] = SuggestionStatus::Pending;
                        $suggestion = ScopusSuggestion::query()->create($attributes + ['scopus_profile_id' => $profile->id]);
                        $this->autoImport($suggestion);
                        $created++;
                    } else {
                        unset($attributes['raw_payload']);
                        // A refresh reuses the lightweight search summary, not a full
                        // abstract fetch, so it lacks fields (authors, publisher,
                        // conference, ...) that were only ever populated by an earlier
                        // abstract request. Never let a missing value blank one out.
                        $attributes = array_filter($attributes, fn (mixed $value): bool => $value !== null);
                        $existing->update($attributes);
                        $existing->refresh();
                        $suggestion = $existing;
                        $updated++;
                    }

                    event(new WorkDiscovered($profile, $suggestion, $existing === null));
                }

                $profile->forceFill([
                    'discovery_cursor' => $page->complete ? null : $page->nextStart,
                    'discovery_total' => $page->complete ? null : $page->total,
                ])->save();
            }

            $result = new DiscoveryResult($found, $created, $updated, $matched);
            $profile->forceFill([
                'sync_status' => SyncStatus::Success,
                'sync_error' => null,
                'last_synced_at' => now(),
                'discovery_cursor' => null,
                'discovery_total' => null,
            ])->save();
            $this->finishRun($run, SyncStatus::Success, $result);

            return $result;
        } catch (Throwable $exception) {
            $this->fail($profile, $run, $exception);
            throw $exception;
        }
    }

    private function startRun(ScopusProfile $profile, string $operation): ScopusSyncRun
    {
        return ScopusSyncRun::query()->create([
            'scopus_profile_id' => $profile->id,
            'operation' => $operation,
            'status' => SyncStatus::Running,
            'started_at' => now(),
        ]);
    }

    private function finishRun(ScopusSyncRun $run, SyncStatus $status, ?DiscoveryResult $result = null): void
    {
        $run->update([
            'status' => $status,
            'found_count' => $result->found ?? 0,
            'created_count' => $result->created ?? 0,
            'updated_count' => $result->updated ?? 0,
            'matched_count' => $result->matched ?? 0,
            'finished_at' => now(),
        ]);
    }

    private function fail(ScopusProfile $profile, ScopusSyncRun $run, Throwable $exception): void
    {
        $error = str($exception->getMessage())->limit(1000, '')->toString();

        DB::transaction(function () use ($profile, $run, $error): void {
            $profile->forceFill(['sync_status' => SyncStatus::Failed, 'sync_error' => $error])->save();
            $run->update(['status' => SyncStatus::Failed, 'error' => $error, 'finished_at' => now()]);
        });

        $profile->refresh();
        $run->refresh();
        event(new SyncFailed($profile, $run, $exception));
    }

    private function autoImport(ScopusSuggestion $suggestion): void
    {
        if (! config('scopus-laravel.discovery.auto_import', false)) {
            return;
        }

        try {
            $this->importSuggestion->execute($suggestion);
        } catch (Throwable $exception) {
            $suggestion->update([
                'status' => SuggestionStatus::Failed,
                'match_reason' => str($exception->getMessage())->limit(255, '')->toString(),
                'resolved_at' => now(),
            ]);
        }
    }
}
