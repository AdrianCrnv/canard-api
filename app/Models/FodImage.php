<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FodImage extends Model
{
    use HasFactory;

    protected $table = 'fod_image';

    protected $fillable = [
        'fod_id',
        'image_path',
        'thumbnail_path',
        'is_fod',
        'reviewed',
    ];

    public function fod()
    {
        return $this->belongsTo(ResultFod::class, 'fod_id');
    }

    public function fodDetections()
    {
        return $this->hasMany(FodDetection::class, 'image_id');
    }
}
