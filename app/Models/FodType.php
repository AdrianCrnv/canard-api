<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FodType extends Model
{
    use HasFactory;

    protected $table = 'fod_types';

    public $timestamps = false;

    protected $fillable = [
        'type',
    ];
}
