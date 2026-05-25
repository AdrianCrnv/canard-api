<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PciDetection extends Model
{
    use HasFactory;

    protected $table = 'pci_detection';

    protected $fillable = [
        'image_id',
        'detection_number',
        'polygon_points',
        'polygon_area_cm2',
        'polygon_centroid_x',
        'polygon_centroid_y',
        'severity',
        'type_id',
        'confidence',
        'coordinate_latitude',
        'coordinate_longitude',
        'coordinate_altitude',
        'is_duplicated',
        'detection_type',
        'removed',
    ];

    protected $casts = [
        'polygon_points' => 'array',
    ];

    public function pciImage()
    {
        return $this->belongsTo(PciImage::class, 'image_id');
    }
}
