<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AircraftPartAircraft extends Model
{
    use HasFactory;

    protected $table = 'aircraft_parts_aircrafts';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $fillable = [
        'aircraft_id',
        'aircraft_part_id',
        'coordinate_x',
        'coordinate_y',
        'coordinate_z',
        'elevation_angle',
        'azimut',
        'distance',
    ];

    public $timestamps = true;

    public function aircraft()
    {
        return $this->belongsTo(Aircraft::class, 'aircraft_id');
    }

    public function aircraftPart()
    {
        return $this->belongsTo(AircraftParts::class, 'aircraft_part_id');
    }
}
