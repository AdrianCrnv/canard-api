<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OperationReports extends Model
{
    use HasFactory;

    protected $table = 'operation_reports';

    protected $fillable = [
        'name',
        'description',
        'type',
        'size',
        'operation_id',
    ];

    public function operation()
    {
        return $this->belongsTo(Operation::class);
    }
}
