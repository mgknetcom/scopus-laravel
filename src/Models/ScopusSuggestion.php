<?php

namespace Mgknetcom\Scopus\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Mgknetcom\Scopus\Enums\ScopusDocumentType;
use Mgknetcom\Scopus\Enums\SuggestionStatus;

/**
 * @property int $id
 * @property int $scopus_profile_id
 * @property string $eid
 * @property string|null $scopus_id
 * @property string|null $doi
 * @property string $title
 * @property string|null $authors
 * @property string|null $document_type
 * @property ScopusDocumentType $mapped_type
 * @property int|null $year
 * @property string|null $isbn
 * @property string|null $issn
 * @property string|null $pages
 * @property string|null $publisher
 * @property string|null $conference
 * @property string|null $source_title
 * @property int $citation_count
 * @property SuggestionStatus $status
 * @property string|null $match_reason
 * @property string|null $imported_model_type
 * @property int|null $imported_model_id
 * @property array<string, mixed>|null $raw_payload
 * @property Carbon $discovered_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ScopusProfile $profile
 * @property-read Model|null $importedModel
 */
class ScopusSuggestion extends Model
{
    protected $table = 'scopus_suggestions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'mapped_type' => ScopusDocumentType::class,
            'status' => SuggestionStatus::class,
            'year' => 'integer',
            'citation_count' => 'integer',
            'imported_model_id' => 'integer',
            'raw_payload' => 'array',
            'discovered_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ScopusProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(ScopusProfile::class, 'scopus_profile_id');
    }

    /** @return MorphTo<Model, $this> */
    public function importedModel(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPending(): bool
    {
        return $this->status === SuggestionStatus::Pending;
    }
}
