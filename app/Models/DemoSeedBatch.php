<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DemoSeedBatch extends Model
{
    use HasFactory;

    protected $table = 'demo_seed_batches';
    public $timestamps = false;

    protected $fillable = [
        'batch_id',
        'label',
        'students_count',
        'status',
        'started_at',
        'finished_at',
        'notes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
