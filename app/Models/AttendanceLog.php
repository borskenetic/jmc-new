<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceLog extends Model
{
    protected $fillable = [
        'student_id',
        'status',
        'is_late',
        'section',
        'gate',
        'scanned_at',
        'client_uuid',
        'gate_device_id',
        'source',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
        'is_late' => 'boolean',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
