<?php

namespace Mgknetcom\Scopus\Actions;

use Illuminate\Support\Facades\DB;
use Mgknetcom\Scopus\Contracts\SuggestionImporter;
use Mgknetcom\Scopus\Data\PublicationReference;
use Mgknetcom\Scopus\Enums\SuggestionStatus;
use Mgknetcom\Scopus\Events\SuggestionImported;
use Mgknetcom\Scopus\Models\ScopusSuggestion;

final class ImportSuggestion
{
    public function __construct(private readonly SuggestionImporter $importer) {}

    public function execute(ScopusSuggestion $suggestion): PublicationReference
    {
        /** @var array{PublicationReference, ScopusSuggestion, bool} $result */
        $result = DB::transaction(function () use ($suggestion): array {
            $locked = ScopusSuggestion::query()->lockForUpdate()->findOrFail($suggestion->id);
            if ($locked->status === SuggestionStatus::Imported && $locked->imported_model_type && $locked->imported_model_id) {
                return [
                    new PublicationReference($locked->imported_model_type, $locked->imported_model_id, 'Already imported'),
                    $locked,
                    false,
                ];
            }

            $reference = $this->importer->import($locked);
            $locked->update([
                'status' => SuggestionStatus::Imported,
                'imported_model_type' => $reference->modelType,
                'imported_model_id' => $reference->modelId,
                'match_reason' => $reference->reason,
                'resolved_at' => now(),
            ]);

            return [$reference, $locked, true];
        });

        [$reference, $imported, $wasImported] = $result;
        if ($wasImported) {
            $imported->refresh();
            event(new SuggestionImported($imported, $reference));
        }

        return $reference;
    }
}
