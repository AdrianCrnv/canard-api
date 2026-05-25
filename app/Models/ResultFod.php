<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultFod extends Model
{
    use HasFactory;

    protected $table = 'results_fod';

    protected $fillable = [
        'operation_id',
        'task_id',
        'run',
        'is_valid',
        'images',
        'status',
        'process_uuid',
        'read_yaml',
        'num_imgs_processed',
        'params_id',
    ];

    public function fodImages()
    {
        return $this->hasMany(FodImage::class, 'fod_id');
    }

    public function operation()
    {
        return $this->belongsTo(Operation::class, 'operation_id');
    }

    public function params()
    {
        return $this->belongsTo(ResultsFodParams::class, 'params_id');
    }
}
