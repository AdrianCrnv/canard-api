<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AerodromeBeacon extends Model
{
    use HasFactory;

    protected $table = 'aerodrome_beacon';

    protected $fillable = [
        'name',
        'airport_id',
        'coordinate_latitude',
        'coordinate_longitude',
        'coordinate_altitude',
    ];

    public function airport()
    {
        return $this->belongsTo(Airport::class);
    }
}