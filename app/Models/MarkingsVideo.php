<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class MarkingsVideo extends Model
{
    use HasFactory;

    protected $table = 'markings_videos';

    public $timestamps = false;

    protected $fillable = [
        'result_rwy_marking_id',
        'file_type',
        'filename',
        's3_path',
        'size_bytes',
        'srt_id'
    ];

    protected $appends = ['url'];

    public function resultRwyMarking()
    {
        return $this->belongsTo(ResultsRwyMarkings::class, 'result_rwy_marking_id');
    }

    public function srt()
    {
        return $this->belongsTo(MarkingsVideo::class, 'srt_id');
    }

    public function video()
    {
        return $this->hasOne(MarkingsVideo::class, 'srt_id');
    }

    public function getUrlAttribute()
    {
        if ($this->s3_path && Storage::disk('s3')->exists($this->s3_path)) {
            return Storage::disk('s3')->temporaryUrl($this->s3_path, now()->addMinutes(20));
        }
        return null;
    }
}
