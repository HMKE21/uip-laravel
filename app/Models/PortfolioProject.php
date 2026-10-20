<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PortfolioProject extends Model
{
    use HasFactory;

    protected $table = 'portfolio_projects';
    public $incrementing = false;
    // Composite primary key (portfolio_id, project_id) - Eloquent has no native support;
    // query via where() clauses, e.g. static::where('col1', $a)->where('col2', $b).
    public $timestamps = false;

    protected $fillable = [
        'display_order',
    ];

    // Relationships

    public function portfolio()
    {
        return $this->belongsTo(Portfolio::class, 'portfolio_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
