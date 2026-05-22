<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CalibrationToolApk extends Model
{
    use HasFactory;

    protected $guarded = []; // Allow all fields to be saved as Mass Assignment
    protected $table = 'releases_app'; // Set non-standard table name
    protected $fillable = ['version_name', 'version_code', 'length', 'description'];

}
