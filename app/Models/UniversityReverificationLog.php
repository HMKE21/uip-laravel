<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UniversityReverificationLog extends Model
{
    use HasFactory;

    protected $table = 'university_reverification_log';
    const UPDATED_AT = null;

    protected $fillable = [
        'university_id',
        'event',
        'method',
        'notes',
        'actor_id',
    ];

    // Relationships

    public function university()
    {
        return $this->belongsTo(University::class, 'university_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
