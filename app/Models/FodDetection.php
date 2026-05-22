<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FodDetection extends Model
{
    use HasFactory;

    protected $table = 'fod_detection';

    protected $fillable = [
        'image_id',
        'detection_number',
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
        'removed',
    ];

    public function fodImage()
    {
        return $this->belongsTo(FodImage::class, 'image_id');
    }
}
