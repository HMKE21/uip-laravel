<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SecurityIncidentEvent extends Model
{
    use HasFactory;

    protected $table = 'security_incident_events';
    const UPDATED_AT = null;

    protected $fillable = [
        'incident_id',
        'user_id',
        'event_type',
        'note',
    ];

    // Relationships

    public function incident()
    {
        return $this->belongsTo(SecurityIncident::class, 'incident_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
