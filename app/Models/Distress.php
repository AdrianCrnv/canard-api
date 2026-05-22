<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Distress extends Model {

    use HasFactory;

    protected $table = 'distresses';

    protected $fillable = [
        'id',
        'frame_id',
        'type_id',
        'severity_id',
        'is_auto',
        'description',
        'distress_id',
        'coords_center_lat',
        'coords_center_lon',
        'area',
        'observations'
    ];

    public function taxiway(){
        return $this->belongsTo(Taxiway::class);
    }

    public function runway(){
        return $this->belongsTo(Runway::class);
    }

    // this method could be a relation with belongsTo(DistressType::class,'type_id').
    // The second parameter is the name of the foreign key
    public function type(){
        return DistressType::where('id', $this->type_id)
                        ->first();
    }

    public function framesCoords(){
        return $this->belongsTo(FramesCoords::class, 'frame_id');
    }

    public function severity(){
        return $this->belongsTo(DistressSeverity::class,'severity_id');
    }

}
