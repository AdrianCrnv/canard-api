<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CompanyUser extends Pivot
{
    use HasFactory;

    protected $table = 'company_user';

    protected $fillable = [
        'company_id',
        'user_id',
    ];
}
