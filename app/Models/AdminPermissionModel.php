<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdminPermissionModel extends Model
{
    // 权限删除需要与角色、菜单和按钮一致地走软删除，避免历史授权关系被物理删除。
    use HasFactory, SoftDeletes;

    protected $table = 'admin_permissions';

    protected $fillable = [
        'code',
        'name',
        'description',
        'menu_id',
        'button_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'menu_id' => 'integer',
        'button_id' => 'integer',
    ];

    // ==================== 关联关系 ====================

    /**
     * 权限关联的角色
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(AdminRoleModel::class, 'admin_role_permissions', 'permission_id', 'role_id')
            ->withTimestamps();
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(MenuModel::class, 'menu_id')->withTrashed();
    }

    public function button(): BelongsTo
    {
        return $this->belongsTo(ButtonModel::class, 'button_id')->withTrashed();
    }

    public function isGeneric(): bool
    {
        return $this->menu_id === null && $this->button_id === null;
    }

    public function scopeEffective(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where(function (Builder $generic): void {
                $generic->whereNull('menu_id')->whereNull('button_id');
            })->orWhere(function (Builder $menuPermission): void {
                $menuPermission->whereNotNull('menu_id')
                    ->whereNull('button_id')
                    ->whereHas('menu', function (Builder $menu): void {
                        $menu->effective();
                    });
            })->orWhere(function (Builder $buttonPermission): void {
                $buttonPermission->whereNull('menu_id')
                    ->whereNotNull('button_id')
                    ->whereHas('button', function (Builder $button): void {
                        $button->effective();
                    });
            });
        });
    }

    public function isEffective(): bool
    {
        if ($this->isGeneric()) {
            return true;
        }

        if ($this->menu_id !== null) {
            return $this->menu !== null
                && $this->menu->isEnabled()
                && $this->menu->hasEnabledAncestors();
        }

        return $this->button !== null && $this->button->isEnabled();
    }
}
