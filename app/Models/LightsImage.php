<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LightsImage extends Model
{
    use HasFactory;

    protected $table = 'lights_image';

    protected $fillable = [
        'type_id',
        'results_rwy_lights_id',
        'txy_id',
        'direction',
        'image_path',
        'reviewed',
        'type_upload',
        'flight_altitude',
        'thumbnail_path',
        'comment',
    ];

    public function operationType()
    {
        return $this->belongsTo(OperationType::class, 'type_id');
    }

    public function resultsRwyLight()
    {
        return $this->belongsTo(ResultsRwyLights::class, 'results_rwy_lights_id');
    }

    public function detections()
    {
        return $this->hasMany(LightsDetection::class, 'image_id');
    }
}
