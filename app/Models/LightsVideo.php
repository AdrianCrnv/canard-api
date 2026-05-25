<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class LightsVideo extends Model
{
    use HasFactory;

    protected $table = 'lights_videos';
    public $timestamps = false;

    protected $fillable = [
        'result_rwy_light_id',
        'file_type',
        'filename',
        'size_bytes',
    ];

    protected $casts = [
        'result_rwy_light_id' => 'integer',
        'size_bytes' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->created_at) {
                $model->created_at = now();
            }
        });
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(ResultsRwyLights::class, 'result_rwy_light_id');
    }

    public function resultRwyLight(): BelongsTo
    {
        return $this->inspection();
    }

    public function scopeVideoFiles($query)
    {
        return $query->where('file_type', 'video');
    }

    public function scopeSrtFiles($query)
    {
        return $query->where('file_type', 'srt');
    }

    public function scopeForInspection($query, $inspectionId)
    {
        return $query->where('result_rwy_light_id', $inspectionId);
    }

    public function isVideo(): bool
    {
        return $this->file_type === 'video';
    }

    public function isSrt(): bool
    {
        return $this->file_type === 'srt';
    }

    public function getS3Path(): string
    {
        $inspection = $this->inspection;
        return sprintf(
            'Lights/%s/%s/%s/%s/%s',
            $inspection->operation_id,
            $inspection->task_id,
            $inspection->run,
            $inspection->side,
            $this->filename
        );
    }

    public function getS3Uri(): string
    {
        $bucket = config('filesystems.disks.s3.bucket');
        return sprintf('s3://%s/%s', $bucket, $this->getS3Path());
    }

    public function existsInS3(): bool
    {
        return Storage::disk('s3')->exists($this->getS3Path());
    }

    public function getTemporaryUrl(int $minutes = 60): string
    {
        return Storage::disk('s3')->temporaryUrl(
            $this->getS3Path(),
            now()->addMinutes($minutes)
        );
    }

    public function deleteFromS3(): bool
    {
        if ($this->existsInS3()) {
            return Storage::disk('s3')->delete($this->getS3Path());
        }
        return false;
    }

    public function getFormattedSize(): string
    {
        if (!$this->size_bytes) {
            return 'N/A';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = $this->size_bytes;
        $unit = 0;

        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes /= 1024;
            $unit++;
        }

        return round($bytes, 2) . ' ' . $units[$unit];
    }

    public function getMetadata(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->file_type,
            'filename' => $this->filename,
            'size_bytes' => $this->size_bytes,
            'size_formatted' => $this->getFormattedSize(),
            's3_path' => $this->getS3Path(),
            's3_uri' => $this->getS3Uri(),
            'exists_in_s3' => $this->existsInS3(),
            'created_at' => $this->created_at->toDateTimeString(),
        ];
    }
}
