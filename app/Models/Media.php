<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    use HasFactory;

    protected $table = 'media';

    protected $fillable = [
        'model_type',
        'model_id',
        'collection_name',
        'name',
        'file_name',
        'subject_id',
        'is_measured',
        'mime_type',
        'disk',
        'size',
        'manipulations',
        'custom_properties',
        'responsive_images',
        'order_column',
        'uuid',
        'created_at',
        'updated_at',
    ];
}
