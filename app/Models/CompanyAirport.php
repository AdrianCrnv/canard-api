<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CompanyAirport extends Pivot
{
    use HasFactory;

    protected $table = 'company_airports';

    protected $fillable = [
        'company_id',
        'airport_id',
    ];
}
