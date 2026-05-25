<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResultsRwyMarkings extends Model
{
    use HasFactory;

    protected $table = 'results_rwy_markings';

    protected $fillable = [
        'task_id',
        'rwy_id',
        'operation_id',
        'run',
        'is_valid',
        'status',
        'process_uuid',
        'read_yaml',
        'num_imgs_processed',
        'is_video',
        'objective_mpf',
        'fly_speed',
    ];

    protected $casts = [
        'is_valid' => 'boolean',
        'is_video' => 'boolean',
        'run' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'read_yaml' => 'boolean',
        'num_imgs_processed' => 'integer',
    ];

    public function videos(): HasMany
    {
        return $this->hasMany(MarkingsVideo::class, 'result_rwy_marking_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function runway(): BelongsTo
    {
        return $this->belongsTo(Runway::class, 'rwy_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class, 'operation_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(MarkingsImage::class, 'rwy_id', 'id');
    }

    protected $appends = ['total_images', 'total_videos'];

    public function getTotalImagesAttribute()
    {
        if (!isset($this->attributes['total_images'])) {
            return $this->is_video ? 0 : $this->images->count();
        }
        return $this->attributes['total_images'];
    }

    public function getTotalVideosAttribute()
    {
        if (!isset($this->attributes['total_videos'])) {
            return $this->is_video ? $this->videos->where('file_type', 'video')->count() : 0;
        }
        return $this->attributes['total_videos'];
    }
}
