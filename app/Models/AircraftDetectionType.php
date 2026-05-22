<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AircraftDetectionType extends Model
{
    use HasFactory;

    protected $table = 'aircraft_defects_types';

    public $timestamps = false;

    protected $fillable = [
        'type',
    ];
}
