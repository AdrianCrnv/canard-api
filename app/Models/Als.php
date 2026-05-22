<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Als extends Model implements HasMedia {

    use HasFactory;
    use InteractsWithMedia;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment

    public function bars(){
        return $this->hasMany(AlsBar::class);
    }

    public function header(){
        return $this->belongsTo(Header::class);
    }
}
