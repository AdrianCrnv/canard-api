<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AirportManager extends Model {

    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment

    public function airports(){
        return $this->hasMany(Airport::class);
    }

    public function country(){
        return $this->belongsTo(Country::class);
    }

    public function contact(){
        return $this->belongsTo(Contact::class);
    }
}
