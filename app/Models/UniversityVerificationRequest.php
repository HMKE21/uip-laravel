<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UniversityVerificationRequest extends Model
{
    use HasFactory;

    protected $table = 'university_verification_requests';
    const UPDATED_AT = null;

    protected $fillable = [
        'university_id',
        'document_path',
        'notes',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    // Relationships

    public function university()
    {
        return $this->belongsTo(University::class, 'university_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
