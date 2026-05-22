<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DistressPolygon extends Model
{
    use HasFactory;

    protected $table = 'distress_polygon';

    protected $fillable = [
        'id',
        'distress_id'
    ];

    public function DistressPolygon(){
        return $this->hasMany(PolygonVertex::class);
    }

    public function PolygonVertex(){
        return $this->hasMany(PolygonVertex::class, 'polygon_id');
    }
}
