<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultsWdi extends Model
{
    use HasFactory;

    protected $table = 'results_wdi';

    protected $fillable = [
        'operation_id',
        'task_id',
        'run',
        'wdi_id',
        'is_valid',
    ];

    public function operation()
    {
        return $this->belongsTo(Operation::class);
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function wdi()
    {
        return $this->belongsTo(Wdi::class);
    }

    public function files()
    {
        return $this->hasMany(WdiFile::class, 'result_id');
    }
}
