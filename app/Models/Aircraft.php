<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Aircraft extends Model {

    use HasFactory;

    protected $table = 'aircrafts';

    protected $fillable = [
        'model', 'manufacturer'
    ];

    public function operation()
    {
        return $this->belongsTo(Operation::class);
    }

    public function dimensions()
    {
        return $this->hasOne(AircraftDimension::class, 'aircraft_id');
    }

    public function parts()
    {
        return $this->hasMany(AircraftPartAircraft::class, 'aircraft_id');
    }

}
