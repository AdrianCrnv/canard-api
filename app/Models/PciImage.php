<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PciImage extends Model
{
    use HasFactory;

    protected $table = 'pci_image';

    protected $fillable = [
        'pci_id',
        'image_path',
        'thumbnail_path',
        'is_pci',
        'reviewed',
    ];

    public function pci()
    {
        return $this->belongsTo(ResultPci::class, 'pci_id');
    }

    public function pciDetections()
    {
        return $this->hasMany(PciDetection::class, 'image_id');
    }
}
