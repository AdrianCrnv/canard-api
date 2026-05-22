<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FlightTurnImage extends Model
{
    use HasFactory;

    protected $table = 'flight_turn_image';

    protected $fillable = [
        'ft_id',
        'image_path',
        'reviewed',
    ];

    public function flightTurnResult()
    {
        return $this->belongsTo(ResultAcMaint::class, 'ft_id');
    }

}
