<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GviSectionType extends Model
{
    use HasFactory;

    protected $table = 'gvi_section_types';
    protected $primaryKey = 'id';
    public $timestamps = false;
    protected $fillable = ['id', 'name'];
}
