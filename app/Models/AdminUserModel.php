<?php

namespace App\Models;

use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class AdminUserModel extends Authenticatable
{
    use HasApiTokens, HasFactory, SoftDeletes;

    protected $table = 'admin_users';

    protected $fillable = [
        'username',
        'password',
        'phone',
        'email',
        'real_name',
        'status',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'password' => 'hashed',
        'status' => UserStatus::class,
        'last_login_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // ==================== 关联关系 ====================

    /**
     * 用户拥有的角色
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(AdminRoleModel::class, 'admin_user_roles', 'user_id', 'role_id')
            ->withTimestamps();
    }

    /**
     * 用户拥有的权限（通过角色）
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            AdminPermissionModel::class,
            'admin_role_permissions',
            'role_id',
            'permission_id',
            'id',
            'id'
        )->join('admin_user_roles', 'admin_role_permissions.role_id', '=', 'admin_user_roles.role_id')
            ->where('admin_user_roles.user_id', $this->id);
    }

    // ==================== 查询作用域 ====================

    /**
     * 激活状态的用户
     */
    public function scopeActive($query)
    {
        return $query->where('status', UserStatus::ACTIVE->value);
    }

    /**
     * 可登录状态（仅 active）
     */
    public function scopeCanLogin($query)
    {
        return $query->where('status', UserStatus::ACTIVE->value);
    }

    // ==================== 业务方法 ====================

    /**
     * 是否可以登录
     */
    public function canLogin(): bool
    {
        return $this->status === UserStatus::ACTIVE;
    }
}
