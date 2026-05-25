<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultPapiVisualInspection extends Model
{
    use HasFactory;

    protected $table = 'results_papi_visual_inspection';

    protected $fillable = [
        'operation_id',
        'symmetry_satisfactory',
        'symmetry_comments',
        'intensity_satisfactory',
        'intensity_comments',
        'transition_satisfactory',
        'transition_comments',
        'observations',
    ];

    protected $casts = [
        'symmetry_satisfactory'  => 'string',
        'intensity_satisfactory' => 'string',
        'transition_satisfactory'=> 'string',
    ];

    public function operation()
    {
        return $this->belongsTo(Operation::class);
    }
}
