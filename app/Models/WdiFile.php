<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WdiFile extends Model
{
    use HasFactory;

    protected $table = 'wdi_files';

    protected $fillable = [
        's3_path',
        'description',
        'type',
        'size',
        'result_id',
    ];

    public function result()
    {
        return $this->belongsTo(ResultsWdi::class, 'result_id');
    }

    public function getFormattedSizeAttribute()
    {
        $bytes = $this->size;
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return number_format($bytes / 1024, 2) . ' KB';
        if ($bytes < 1073741824) return number_format($bytes / 1048576, 2) . ' MB';
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
}
