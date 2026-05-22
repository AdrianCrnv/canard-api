<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Etod extends Model {

    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment

    public function airport(){
        return $this->belongsTo(Airport::class);
    }

    public function area(){
        return $this->belongsTo(EtodAreas::class);
    }

    public function parameters(){
        return Parameter::where('subject_type_id', 10)
                        ->where('subject_id', $this->id)
                        ->get();
    }

    public function markerPoints(){
        return $this->hasMany(MarkerPoints::class, 'subject_id')->where('subject_type_id', 10);
    }

}
