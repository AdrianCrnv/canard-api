<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AcMaintImage extends Model
{
    use HasFactory;

    protected $table = 'aircraft_maintenance_image';

    protected $fillable = [
        'ac_maint_id',
        'image_path',
        'thumbnail_path',
        'reviewed',
    ];

    public function maintenanceResult()
    {
        return $this->belongsTo(ResultAcMaint::class, 'ac_maint_id');
    }

    public function detections()
    {
        return $this->hasMany(AcMaintDetection::class, 'image_id');
    }
}