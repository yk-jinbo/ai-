<?php

namespace App\Models;

use App\Enums\ResourceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdminRoleModel extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'admin_roles';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_super_admin',
        'status',
    ];

    protected $casts = [
        'is_super_admin' => 'boolean',
        'status' => ResourceStatus::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // ==================== 关联关系 ====================

    /**
     * 角色关联的用户
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(AdminUserModel::class, 'admin_user_roles', 'role_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * 角色拥有的权限
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(AdminPermissionModel::class, 'admin_role_permissions', 'role_id', 'permission_id')
            ->withTimestamps();
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('deleted_at');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->live()->where('status', ResourceStatus::ACTIVE->value);
    }

    public function isEnabled(): bool
    {
        return $this->deleted_at === null && $this->status?->isActive() === true;
    }
}
