<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wdi extends Model
{
    use HasFactory;

    protected $table = 'wdi';

    protected $fillable = [
        'name',
        'airport_id',
        'latitude',
        'longitude',
        'altitude'
    ];

    public function airport()
    {
        return $this->belongsTo(Airport::class);
    }
}
