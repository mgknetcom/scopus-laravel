<?php

namespace Mgknetcom\Scopus\Console;

use Illuminate\Console\Command;
use Mgknetcom\Scopus\Enums\SuggestionStatus;
use Mgknetcom\Scopus\Models\ScopusSuggestion;
use Mgknetcom\Scopus\Models\ScopusSyncRun;

final class PruneScopusDataCommand extends Command
{
    protected $signature = 'scopus:prune
        {--runs= : Delete completed sync runs older than this many days}
        {--resolved= : Delete resolved suggestions older than this many days}
        {--raw= : Clear raw payloads older than this many days}
        {--dry-run : Report matching rows without changing data}';

    protected $description = 'Prune old Scopus synchronization history, resolved suggestions and raw API payloads';

    public function handle(): int
    {
        $runsDays = $this->days('runs', 'sync_runs_days');
        $resolvedDays = $this->days('resolved', 'resolved_suggestions_days');
        $rawDays = $this->days('raw', 'raw_payloads_days');

        if ($runsDays === null || $resolvedDays === null || $rawDays === null) {
            $this->error('Retention values must be non-negative integers.');

            return self::FAILURE;
        }

        $runs = ScopusSyncRun::query()
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', now()->subDays($runsDays));
        $resolved = ScopusSuggestion::query()
            ->whereNot('status', SuggestionStatus::Pending->value)
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<', now()->subDays($resolvedDays));
        $raw = ScopusSuggestion::query()
            ->whereNotNull('raw_payload')
            ->where('discovered_at', '<', now()->subDays($rawDays));

        $counts = [
            'sync runs' => (clone $runs)->count(),
            'resolved suggestions' => (clone $resolved)->count(),
            'raw payloads' => (clone $raw)->count(),
        ];

        if ($this->option('dry-run')) {
            foreach ($counts as $label => $count) {
                $this->line("Would prune {$count} {$label}.");
            }

            return self::SUCCESS;
        }

        $deletedRuns = $runs->delete();
        $deletedSuggestions = $resolved->delete();
        $clearedPayloads = $raw->update(['raw_payload' => null]);

        $this->info("Pruned {$deletedRuns} sync runs, {$deletedSuggestions} resolved suggestions and {$clearedPayloads} raw payloads.");

        return self::SUCCESS;
    }

    private function days(string $option, string $configKey): ?int
    {
        $value = $this->option($option);
        $value = $value === null
            ? config('scopus-laravel.retention.'.$configKey)
            : $value;
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $validated === false ? null : $validated;
    }
}
