<?php

namespace Mgknetcom\Scopus\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Mgknetcom\Scopus\Enums\SyncStatus;

/**
 * @property int $id
 * @property int $scopus_profile_id
 * @property string $operation
 * @property SyncStatus $status
 * @property int $found_count
 * @property int $created_count
 * @property int $updated_count
 * @property int $matched_count
 * @property string|null $error
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ScopusProfile $profile
 */
class ScopusSyncRun extends Model
{
    protected $table = 'scopus_sync_runs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SyncStatus::class,
            'found_count' => 'integer',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'matched_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ScopusProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(ScopusProfile::class, 'scopus_profile_id');
    }
}
