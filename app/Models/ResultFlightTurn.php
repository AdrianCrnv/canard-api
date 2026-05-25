<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultFlightTurn extends Model
{
    use HasFactory;

    protected $table = 'results_flight_turn';

    protected $fillable = [
        'operation_id',
        'task_id',
        'run',
        'is_valid',
        'process_uuid',
        'read_yaml',
    ];

    public function operation()
    {
        return $this->belongsTo(Operation::class);
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function images()
    {
        return $this->hasMany(FlightTurnImage::class, 'ft_id');
    }
}
