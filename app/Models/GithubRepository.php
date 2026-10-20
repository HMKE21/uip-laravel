<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق موديل GithubRepository القديم بالظبط (migration 027) — صف واحد
 * لكل مشروع ربط ريبو GitHub. مفيش updated_at في الجدول القديم (created_at
 * بس)، فمعمول UPDATED_AT = null زي AuditLog.
 */
class GithubRepository extends Model
{
    const UPDATED_AT = null;

    protected $table = 'github_repositories';

    protected $fillable = [
        'project_id', 'repo_url', 'default_branch', 'last_synced_at', 'sync_status',
    ];
}
