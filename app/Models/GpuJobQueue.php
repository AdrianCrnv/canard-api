<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpuJobQueue extends Model
{
    use HasFactory;

    protected $table = 'gpu_job_queue';

    protected $fillable = [
        'result_type',
        'result_id',
        'process_uuid',
        'status',
        'attempts',
        'error_message',
    ];

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Jobs que llevan más de $minutes minutos en 'processing' con process_uuid ya asignado.
     * Una vez la GPU acepta el job (process_uuid != null) debería completar en ~5 min.
     * Si supera el threshold, se considera colgado.
     */
    public function scopeStale($query, int $minutes = 10)
    {
        return $query->where('status', 'processing')
                     ->whereNotNull('process_uuid')
                     ->where('updated_at', '<', now()->subMinutes($minutes));
    }

    public function markAsProcessing()
    {
        $this->update(['status' => 'processing']);
    }

    public function markAsDone()
    {
        $this->update(['status' => 'done']);
    }

    public function markAsFailed(string $error)
    {
        $this->update([
            'status'        => 'failed',
            'error_message' => $error,
            'attempts'      => $this->attempts + 1,
        ]);
    }
}
