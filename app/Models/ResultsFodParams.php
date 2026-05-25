<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultsFodParams extends Model
{
    use HasFactory;

    protected $table = 'results_fod_params';

    public $timestamps = false;

    protected $fillable = [
        'camera_id',
        'focal_length',
        'altitude',
        'patch_overlap',
        'capture_speed',
    ];

    public function camera()
    {
        return $this->belongsTo(Camera::class, 'camera_id');
    }

    public function resultsFod()
    {
        return $this->hasMany(ResultsFod::class, 'params_id');
    }
}
