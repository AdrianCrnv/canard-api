<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PciType extends Model
{
    use HasFactory;

    protected $table = 'pci_types';

    public $timestamps = false;

    protected $fillable = [
        'type',
    ];

    public function pciDetections()
    {
        return $this->hasMany(PciDetection::class, 'type_id');
    }
}
