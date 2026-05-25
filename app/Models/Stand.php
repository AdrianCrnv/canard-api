<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stand extends Model
{
    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment

    public static function getStand($id)
    {
        return self::find($id);
    }

    public function airport()
    {
        return $this->belongsTo(Airport::class);
    }

    public function aircrafts()
    {
        //return $this->belongsToMany(Aircraft::class, 'stands_aircrafts');
        return $this->belongsToMany(Aircraft::class, 'stands_aircrafts', 'stand_id', 'aircraft_id');
    }
}
