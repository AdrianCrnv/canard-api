<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LightsDetection extends Model
{
    use HasFactory;

    protected $table = 'lights_detections';

    protected $fillable = [
        'image_id',
        'detection_number',
        'pixel_x',
        'pixel_y',
        'bbox_x',
        'bbox_y',
        'bbox_width',
        'bbox_height',
        'coordinate_latitude',
        'coordinate_longitude',
        'type_id',
        'status_id',
        'reviewed',
        'patch_path',
        'unique_detection_id',
    ];

    protected $casts = [
        'pixel_x'              => 'decimal:2',
        'pixel_y'              => 'decimal:2',
        'bbox_x'               => 'integer',
        'bbox_y'               => 'integer',
        'bbox_width'           => 'integer',
        'bbox_height'          => 'integer',
        'coordinate_latitude'  => 'decimal:6',
        'coordinate_longitude' => 'decimal:6',
        'reviewed'             => 'boolean',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime',
    ];

    public function image()
    {
        return $this->belongsTo(LightsImage::class, 'image_id');
    }

    public function type()
    {
        return $this->belongsTo(LightsType::class, 'type_id');
    }

    public function status()
    {
        return $this->belongsTo(LightsStatus::class, 'status_id');
    }
}
