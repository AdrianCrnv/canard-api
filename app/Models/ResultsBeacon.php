<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultsBeacon extends Model
{
    use HasFactory;

    protected $table = 'results_beacon';

    protected $fillable = [
        'operation_id',
        'task_id',
        'beacon_id',
    ];

    public function operation()
    {
        return $this->belongsTo(Operation::class, 'operation_id');
    }

    public function task()
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function beacon()
    {
        return $this->belongsTo(AerodromeBeacon::class, 'beacon_id');
    }
}
