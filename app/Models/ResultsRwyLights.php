<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResultsRwyLights extends Model
{
    use HasFactory;

    /**
     * Tabla asociada
     */
    protected $table = 'results_rwy_lights';

    /**
     * Campos asignables en masa
     */
    protected $fillable = [
        'task_id',
        'rwy_id',
        'operation_id',
        'run',
        'side',
        'content_type',
        'fly_speed',
        'objective_mpf',
        'processing_status',
        'process_uuid',
        'is_valid',
        'is_video',
    ];

    /**
     * Campos que deben ser casteados
     */
    protected $casts = [
        'run' => 'integer',
        'fly_speed' => 'decimal:2',
        'objective_mpf' => 'decimal:2',
        'is_valid' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Valores por defecto
     */
    protected $attributes = [
        'content_type' => 'images',
        'is_valid' => true,
    ];

    // =====================================================
    // RELACIONES
    // =====================================================

    /**
     * Relación con videos (nueva)
     */
    public function videos(): HasMany
    {
        return $this->hasMany(LightsVideo::class, 'result_rwy_light_id');
    }

    /**
     * Relación con la operación
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class, 'operation_id');
    }

    /**
     * Relación con el task/stretch
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    /**
     * Relación con runway
     */
    public function runway(): BelongsTo
    {
        return $this->belongsTo(Runway::class, 'rwy_id');
    }

    // =====================================================
    // SCOPES
    // =====================================================

    public function scopeVideos($query)
    {
        return $query->where('content_type', 'video');
    }

    public function scopeImages($query)
    {
        return $query->where('content_type', 'images');
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('processing_status', $status);
    }

    public function scopeUnprocessed($query)
    {
        return $query->where('content_type', 'video')
                     ->where('processing_status', 'Unprocessed');
    }

    public function scopeByOperationAndTask($query, $operationId, $taskId)
    {
        return $query->where('operation_id', $operationId)
                     ->where('task_id', $taskId);
    }

    // =====================================================
    // MÉTODOS AUXILIARES
    // =====================================================

    public function isVideo(): bool
    {
        return $this->content_type === 'video';
    }

    public function isImages(): bool
    {
        return $this->content_type === 'images';
    }

    public function isProcessed(): bool
    {
        return $this->processing_status === 'Processed';
    }

    public function hasError(): bool
    {
        return $this->processing_status === 'Error';
    }

    public function getS3BasePath(): string
    {
        return sprintf(
            'Lights/%s/%s/%s/%s',
            $this->operation_id,
            $this->task_id,
            $this->run,
            $this->side
        );
    }

    public function getVideoPath(): string
    {
        return $this->getS3BasePath() . '/video.mp4';
    }

    public function getSrtPath(): string
    {
        return $this->getS3BasePath() . '/flight.srt';
    }

    public function getFramesPath(): string
    {
        return $this->getS3BasePath() . '/frames/';
    }

    public function getVideoS3Uri(): string
    {
        $bucket = config('filesystems.disks.s3.bucket');
        return sprintf('s3://%s/%s', $bucket, $this->getVideoPath());
    }

    public function getSrtS3Uri(): ?string
    {
        $bucket = config('filesystems.disks.s3.bucket');
        return sprintf('s3://%s/%s', $bucket, $this->getSrtPath());
    }

    public function markAsProcessed(): bool
    {
        return $this->update(['processing_status' => 'Processed']);
    }

    public function markAsError(): bool
    {
        return $this->update(['processing_status' => 'Error']);
    }

    public function updateProcessingStatus(string $status, ?string $uuid = null): bool
    {
        $data = ['processing_status' => $status];

        if ($uuid !== null) {
            $data['process_uuid'] = $uuid;
        }

        return $this->update($data);
    }

    public function images(): HasMany
    {
        return $this->hasMany(LightsImage::class, 'results_rwy_lights_id')->orderBy('image_path');
    }
}
