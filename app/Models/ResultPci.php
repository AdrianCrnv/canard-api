<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultPci extends Model
{
    use HasFactory;

    protected $table = 'results_pci';

    protected $fillable = [
        'operation_id',
        'task_id',
        'run',
        'is_valid',
        'images',
        'status',
        'process_uuid',
        'read_yaml',
        'num_imgs_processed',
        'params_id',
    ];

    public function pciImages()
    {
        return $this->hasMany(PciImage::class, 'pci_id');
    }

    public function operation()
    {
        return $this->belongsTo(Operation::class, 'operation_id');
    }

    public function params()
    {
        return $this->belongsTo(ResultsPciParams::class, 'params_id');
    }

    public function task()
    {
        return $this->belongsTo(Task::class, 'task_id');
    }
}
