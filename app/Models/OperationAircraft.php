<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OperationAircraft extends Model
{
    use HasFactory;

    protected $fillable = ['operation_id', 'aircraft_id'];

    protected $table = 'operation_aircraft';

    public function aircraft()
    {
        return $this->belongsTo(Aircraft::class, 'aircraft_id');
    }
}
