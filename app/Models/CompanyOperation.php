<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CompanyOperation extends Pivot
{
    use HasFactory;

    protected $table = 'company_operations';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'operation_id',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function operation()
    {
        return $this->belongsTo(Operation::class);
    }
}
