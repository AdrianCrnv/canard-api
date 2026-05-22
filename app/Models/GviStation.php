<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GviStation extends Model
{
    use HasFactory;

    protected $table = 'gvi_stations';
    protected $primaryKey = 'id';
    public $timestamps = false;
    protected $fillable = ['section_id', 'reference_type', 'reference_name'];

    public function sectionType()
    {
        return $this->belongsTo(GviSectionType::class, 'section_id');
    }
}
