<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PolygonVertex extends Model
{
    use HasFactory;

    protected $table = 'polygon_vertex';

    protected $fillable = [
        'id',
        'polygon_id',
        'coords_lat',
        'coords_lon'
    ];

    public function PolygonVertex(){
        return $this->hasMany(Distress::class);
    }
}
