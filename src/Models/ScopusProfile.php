<?php

namespace Mgknetcom\Scopus\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Mgknetcom\Scopus\Enums\SyncStatus;

/**
 * @property int $id
 * @property string|null $owner_type
 * @property int|null $owner_id
 * @property string $author_id
 * @property int $citation_count
 * @property int $document_count
 * @property int|null $h_index
 * @property int|null $cited_by_count
 * @property int|null $coauthor_count
 * @property string|null $given_name
 * @property string|null $surname
 * @property string|null $indexed_name
 * @property string|null $initials
 * @property string|null $orcid
 * @property SyncStatus $sync_status
 * @property string|null $sync_error
 * @property int|null $discovery_cursor
 * @property int|null $discovery_total
 * @property Carbon|null $last_attempt_at
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $owner
 * @property-read Collection<int, ScopusSuggestion> $suggestions
 * @property-read Collection<int, ScopusSyncRun> $syncRuns
 * @property-read int|null $pending_suggestions_count
 */
class ScopusProfile extends Model
{
    protected $table = 'scopus_profiles';

    protected $fillable = ['owner_type', 'owner_id', 'author_id'];

    protected static function booted(): void
    {
        static::saving(function (self $profile): void {
            if (blank($profile->owner_type) && blank($profile->owner_id)) {
                return;
            }

            if (blank($profile->owner_type) || blank($profile->owner_id)) {
                throw ValidationException::withMessages([
                    'owner_type' => 'Owner type and owner ID must be provided together.',
                ]);
            }

            $ownerClass = Relation::getMorphedModel((string) $profile->owner_type) ?? $profile->owner_type;
            if (! is_a($ownerClass, Model::class, true)) {
                throw ValidationException::withMessages(['owner_type' => 'The owner model class is invalid.']);
            }

            if (! $ownerClass::query()->whereKey($profile->owner_id)->exists()) {
                throw ValidationException::withMessages(['owner_id' => 'The selected owner does not exist.']);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'citation_count' => 'integer',
            'document_count' => 'integer',
            'h_index' => 'integer',
            'cited_by_count' => 'integer',
            'coauthor_count' => 'integer',
            'sync_status' => SyncStatus::class,
            'discovery_cursor' => 'integer',
            'discovery_total' => 'integer',
            'last_attempt_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<ScopusSuggestion, $this> */
    public function suggestions(): HasMany
    {
        return $this->hasMany(ScopusSuggestion::class);
    }

    /** @return HasMany<ScopusSyncRun, $this> */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(ScopusSyncRun::class);
    }

    public function ownerLabel(): string
    {
        if ($this->owner === null) {
            return '—';
        }

        foreach (['full_name', 'name', 'title', 'email'] as $attribute) {
            if (filled($this->owner->getAttribute($attribute))) {
                return (string) $this->owner->getAttribute($attribute);
            }
        }

        return class_basename($this->owner).' #'.$this->owner->getKey();
    }
}
