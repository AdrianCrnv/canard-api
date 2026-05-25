<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarkingTypeDefect extends Model
{
    use HasFactory;

    protected $table = 'marking_type_defects';

    protected $fillable = [
        'name',
    ];

    public function defects()
    {
        return $this->hasMany(MarkingDefect::class, 'type_defect');
    }
}
