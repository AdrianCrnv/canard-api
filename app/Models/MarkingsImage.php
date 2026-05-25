<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarkingsImage extends Model
{
    use HasFactory;

    protected $table = 'markings_image';

    protected $fillable = [
        'type_id',
        'rwy_id',
        'task_id',
        'image_path',
        'thumbnail_path',
        'reviewed',
        'type_upload',
        'latitude',
        'longitude',
        'heading',
        'flight_altitude',
        'comment'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function operationType(): BelongsTo
    {
        return $this->belongsTo(OperationType::class, 'type_id');
    }

    public function resultsRwyMarking(): BelongsTo
    {
        return $this->belongsTo(ResultsRwyMarkings::class, 'rwy_id', 'id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ResultsRwyMarkings::class, 'rwy_id', 'id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function taxiway(): BelongsTo
    {
        return $this->belongsTo(Taxiway::class, 'txy_id');
    }
}
