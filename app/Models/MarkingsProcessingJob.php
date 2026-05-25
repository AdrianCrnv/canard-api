<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarkingsProcessingJob extends Model
{
    use HasFactory;

    protected $table = 'markings_processing_jobs';

    protected $fillable = [
        'operation_id',
        'task_id',
        'runway_id',
        'run',
        'result_rwy_marking_id',
        'fly_speed',
        'objective_mpf',
        'process_uuid',
        'video_s3_path',
        'srt_s3_path',
        'video_size_bytes',
        'srt_size_bytes',
        'status',
        'error_message',
    ];

    protected $casts = [
        'fly_speed' => 'decimal:2',
        'objective_mpf' => 'decimal:2',
        'video_size_bytes' => 'integer',
        'srt_size_bytes' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    const STATUS_UPLOADING_VIDEO = 'uploading_video';
    const STATUS_UPLOADING_SRT = 'uploading_srt';
    const STATUS_PROCESSING_FRAMES = 'processing_frames';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    public function operation()
    {
        return $this->belongsTo(Operation::class, 'operation_id');
    }

    public function task()
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function runway()
    {
        return $this->belongsTo(Runway::class, 'runway_id');
    }

    public function result()
    {
        return $this->belongsTo(ResultsRwyMarkings::class, 'result_rwy_marking_id');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING_FRAMES);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeOlderThan($query, $minutes)
    {
        return $query->where('created_at', '<', now()->subMinutes($minutes));
    }

    public function markAsCompleted()
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'process_uuid' => null,
        ]);
    }

    public function markAsFailed($errorMessage = null)
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
        ]);
    }

    public function updateProcessingStatus($processUuid)
    {
        $this->update([
            'status' => self::STATUS_PROCESSING_FRAMES,
            'process_uuid' => $processUuid,
        ]);
    }

    public function isProcessing()
    {
        return in_array($this->status, [
            self::STATUS_UPLOADING_VIDEO,
            self::STATUS_UPLOADING_SRT,
            self::STATUS_PROCESSING_FRAMES,
        ]);
    }

    public function isCompleted()
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed()
    {
        return $this->status === self::STATUS_FAILED;
    }
}
