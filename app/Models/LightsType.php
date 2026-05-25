<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LightsType extends Model
{
    use HasFactory;

    protected $table = 'lights_types';

    protected $fillable = [
        'name',
        'description',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function detections()
    {
        return $this->hasMany(LightsDetection::class, 'type_id');
    }
}
