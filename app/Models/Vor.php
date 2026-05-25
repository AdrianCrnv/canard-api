<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vor extends Model {
    use HasFactory;

    protected $hidden = ['channel_id']; //Hiding Attributes From JSON

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment

    public function airport(){
        return $this->belongsTo(Airport::class);
    }

    public function country(){
        return $this->belongsTo(Country::class);
    }

    public function channel(){
        return $this->belongsTo(VorChannel::class);
    }

    public function parameters(){
        return Parameter::where('subject_type_id', 5)->where('subject_id', $this->id)->get();
    }
}
