<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class ActivityLog extends Model
{
    public $timestamps = false;

    protected $table = 'activity_logs';

    protected $fillable = [
        'user_id',
        'action',
        'model_type',
        'model_id',
        'description',
        'created_at',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function log(string $action, string $modelType, ?int $modelId = null, ?string $description = null, ?int $userId = null): void
    {
        try {
            static::create([
                'user_id'    => $userId ?? auth()->id(),
                'action'     => $action,
                'model_type' => $modelType,
                'model_id'   => $modelId,
                'description' => $description,
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('ActivityLog::log failed: ' . $e->getMessage());
        }
    }
}