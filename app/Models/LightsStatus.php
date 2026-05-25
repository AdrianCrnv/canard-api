<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LightsStatus extends Model
{
    use HasFactory;

    protected $table = 'lights_statuses';

    protected $fillable = [
        'id',
        'name',
        'description',
        'color',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public $incrementing = false;

    public function detections()
    {
        return $this->hasMany(LightsDetection::class, 'status_id');
    }
}
