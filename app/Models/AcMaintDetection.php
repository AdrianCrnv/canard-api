<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AcMaintDetection extends Model
{
    use HasFactory;

    protected $table = 'aircraft_maintenance_detection';

    protected $fillable = [
        'image_id',
        'bbox_x',
        'bbox_y',
        'bbox_width',
        'bbox_height',
        'bbox_dim_cm_width',
        'bbox_dim_cm_height',
        'type_id',
        'confidence',
        'coordinate_latitude',
        'coordinate_longitude',
        'coordinate_altitude',
        'is_duplicated',
        'detection_type',
        'station1',
        'station2',
    ];

    public function image()
    {
        return $this->belongsTo(AcMaintImage::class, 'image_id');
    }

    public function detectionType()
    {
        return $this->belongsTo(AircraftDetectionType::class, 'type_id');
    }
}