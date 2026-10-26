# Model 层说明

## 位置与命名

`app/Models/{实体}Model.php`

每张正式业务表只对应一个 Model。文件名和类名使用业务实体名加 `Model`，不携带数据库物理前缀。

```text
km_admin_users -> app/Models/AdminUserModel.php -> AdminUserModel
km_admin_roles -> app/Models/AdminRoleModel.php -> AdminRoleModel
```

## 双数据库 Model 归属

Model 必须固定声明所属连接，不能在运行时根据请求参数切换：

```php
class MemberModel extends Model
{
    public const TABLE = 'member';

    protected $connection = 'mysql';

    protected $table = self::TABLE;
}

class BridgeRecordModel extends Model
{
    public const TABLE = 'bridge_records';

    protected $connection = 'data_bridge';

    protected $table = self::TABLE;
}
```

`$table` 写逻辑表名，物理前缀由对应连接的 `prefix` 统一追加。例如 `BridgeRecordModel::TABLE` 为 `bridge_records` 时，`data_bridge` 的 `km_` 前缀会映射到物理表 `km_bridge_records`。

## 职责

Model 负责：

- 通过 `$table` 显式映射真实表名；
- 定义 `$fillable`、`$hidden`、`$casts`；
- 定义 Eloquent 关联关系；
- 定义查询作用域；
- 定义访问器、修改器和单模型状态判断。

Model 不负责 HTTP 响应、事务、跨实体流程或业务异常编排。

## 标准写法

```php
class AdminUserModel extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'admin_users';

    protected $fillable = [
        'username',
        'password',
        'status',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'status' => UserStatus::class,
        'created_at' => 'datetime',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            AdminRoleModel::class,
            'admin_user_roles',
            'user_id',
            'role_id',
        )->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', UserStatus::ACTIVE->value);
    }
}
```

## 关联表

纯关联中间表只保存外键、时间戳等关系数据时，在相关 Model 中通过 `belongsToMany()`、`hasMany()` 等定义，不创建空壳 Model/Repository。

关联表具有独立状态、业务字段和独立 CRUD 时，视为业务实体，建立对应 `{实体}Model` 与 `{实体}Repository`。

## 与 Repository 的边界

Model 提供表映射、关系和作用域；Repository 调用这些定义并执行查询。

```php
// AdminUserModel：定义关系
public function roles(): BelongsToMany
{
    return $this->belongsToMany(AdminRoleModel::class, 'admin_user_roles');
}

// AdminUserRepository：执行关联查询
public function loadRoles(AdminUserModel $user): AdminUserModel
{
    return $user->loadMissing('roles');
}
```

## 禁止事项

- 依赖物理表前缀命名为 `KmUserModel`；
- 在 Model 中返回 `JsonResponse`；
- 在 Model 中开启或提交事务；
- 在 Model 中编排多个 Repository 或 Service；
- Service/Controller 直接执行 Model 查询。
- 在 Model 方法内切换连接或根据外部输入拼接连接名。

## 自查

- 文件名、类名均为 `{实体}Model`；
- `$table` 与数据库表名完全一致；
- 字段、类型、隐藏字段定义完整；
- 关联只引用真实存在的 Model；
- 状态字段使用 Enum；
- 对应 `{实体}Repository` 已创建。
