<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DemoSeedLog extends Model
{
    use HasFactory;

    protected $table = 'demo_seed_log';
    const UPDATED_AT = null;

    protected $fillable = [
        'batch_id',
        'table_name',
        'record_id',
    ];
}
