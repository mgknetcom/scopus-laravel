<?php

namespace Mgknetcom\Scopus\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Mgknetcom\Scopus\Exceptions\ScopusApiException;
use Mgknetcom\Scopus\Models\ScopusProfile;
use Mgknetcom\Scopus\Services\ScopusIntegration;

final class DiscoverScopusWorks implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public int $timeout = 900;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $profileId)
    {
        $this->onConnection(config('scopus-laravel.queue.connection'));
        $this->onQueue(config('scopus-laravel.queue.name', 'default'));
    }

    public function uniqueId(): string
    {
        return (string) $this->profileId;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            new RateLimited('scopus-laravel'),
            (new WithoutOverlapping('scopus-discovery-'.$this->profileId))->expireAfter(3600),
        ];
    }

    public function handle(ScopusIntegration $integration): void
    {
        $profile = ScopusProfile::query()->find($this->profileId);
        if ($profile !== null) {
            try {
                $integration->discover($profile);
            } catch (ScopusApiException $exception) {
                if ($exception->retryable && $exception->retryAfterSeconds !== null) {
                    $this->release($exception->retryAfterSeconds);

                    return;
                }

                throw $exception;
            }
        }
    }
}
