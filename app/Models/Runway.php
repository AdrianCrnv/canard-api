<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Runway extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment

    public function airport(){
        return $this->belongsTo(Airport::class);
    }

    public function composition(){
        return $this->belongsTo(RunwayComposition::class);
    }

    public function headers(){
        return $this->hasMany(Header::class);
    }

    public function stretches(){
        return $this->hasMany(Stretch::class, 'subject_id');
    }

    public function parameters(){
        return Parameter::where('subject_type_id', 3)->where('subject_id', $this->id)->get();
    }

    public function vertices(){
        return $this->hasMany(RwyElevationProfile::class, 'rwy_id');
    }
}
