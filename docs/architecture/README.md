# 架构文档

本目录记录可复用的分层开发规范；接口契约和具体实现事实以已确认需求、源码与测试为准。

## 🚀 快速开始

**新手必读：** [架构总览](00_架构总览.md) - 5 分钟了解整体架构

## 📚 文档列表

### 核心层次（必读）

1. **[架构总览](00_架构总览.md)** ⭐
   - 架构图解
   - 各层职责速查表
   - 数据流转示例
   - 一表一 Model/Repository 规则
   - `mysql` / `data_bridge` 双数据库开发规范与跨库示例

2. **[控制器层（Controller）](01_Controller层说明.md)**
   - 作用：HTTP 入口层
   - 职责：接收请求、调用服务层（Service）、返回响应
   - 原则：保持简洁，不写业务逻辑

3. **[服务层（Service）](02_Service层说明.md)** ⭐⭐⭐
   - 作用：核心业务逻辑层
   - 职责：业务流程编排、事务管理
   - 原则：所有业务逻辑都在这里

4. **[模型层（Model）](03_Model层说明.md)**
   - 作用：数据映射层
   - 职责：定义表结构、关联关系、访问器
   - 原则：一张业务表一个 Model，关联关系定义在 Model

### 支撑层次

5. **[仓储层（Repository）](04_Repository层说明.md)**（必需）
   - 作用：统一封装所有数据库访问
   - 何时使用：每张业务表；CRUD 与关联查询统一在对应 Repository
   - Redis key/connection 由 CacheManager 负责，命令由 Service 直接调用

6. **[其他层说明](05_其他层说明.md)**
   - 请求校验层（Request，表单验证）
   - 枚举层（Enum，枚举常量）
   - 异常层（Exception，异常处理）
   - 中间件层（Middleware）

7. **[Response 助手类](06_Response助手类说明.md)**
    - 统一响应格式
    - 当前 API 响应方式
    - 使用静态方法减少重复代码

8. **[缓存层](07_缓存层说明.md)**
   - CacheManager/CacheScope 的职责与调用方式
   - key 命名空间、HMAC 标识和 Redis connection
   - 原生 TTL、数据结构和并发语义边界

## 🎯 三层架构核心

```
Controller → Service → Repository → Model
    ↓           ↓
 Request    CacheManager/CacheScope
    ↓
ResponseHelper → JSON 响应
```

## 数据层命名规则

一张业务表对应一组同名数据层文件，物理表前缀不进入类名或文件名：

```text
km_admin_users       -> AdminUserModel.php       -> AdminUserRepository.php
km_admin_roles       -> AdminRoleModel.php       -> AdminRoleRepository.php
km_admin_permissions -> AdminPermissionModel.php -> AdminPermissionRepository.php
km_admin_personal_access_tokens -> AdminPersonalAccessTokenModel.php -> AdminPersonalAccessTokenRepository.php
```

Model 定义表映射、字段、关联和作用域；Repository 只注入对应 Model，封装该表全部数据库访问。
纯关联中间表由 Model 关系维护，不创建跨表流程 Repository。

## 📖 阅读建议

### 如果你是新手
1. 先看 [架构总览](00_架构总览.md)
2. 然后看 [服务层（Service）](02_Service层说明.md)（最重要）
3. 再看 [控制器层（Controller）](01_Controller层说明.md)
4. 最后看 [模型层（Model）](03_Model层说明.md)

### 如果你想了解某个层
直接跳到对应文档，每个文档包含：
- 📍 位置和目录结构
- 🎯 作用和职责
- ✅ 应该做什么
- ❌ 不应该做什么
- 📝 标准写法和示例
- 🎨 最佳实践
- ❓ 常见问题

## 🔍 快速查找

| 我想... | 看这个 |
|---------|--------|
| 了解整体架构 | [架构总览](00_架构总览.md) |
| 使用两个数据库 | [架构总览](00_架构总览.md)、[服务层（Service）](02_Service层说明.md) |
| 写业务逻辑 | [服务层（Service）](02_Service层说明.md) |
| 写 API 接口 | [控制器层（Controller）](01_Controller层说明.md) |
| 定义数据表、关联和作用域 | [模型层（Model）](03_Model层说明.md) |
| 写 CRUD 和关联查询 | [仓储层（Repository）](04_Repository层说明.md) |
| 验证请求参数 | [请求校验层（Request）](05_其他层说明.md#请求校验层request) |
| 统一 API 响应 | [Response 助手类](06_Response助手类说明.md) |
| 定义状态常量 | [枚举层（Enum）](05_其他层说明.md#枚举层enum) |
| 使用缓存 | [缓存层](07_缓存层说明.md) |

## 💡 核心原则

1. **简洁** - 简单的用简单的方式，不过度设计
2. **刚需** - 只在真正需要时才添加层次
3. **解耦** - 控制器层（Controller）不写业务，服务层（Service）可复用

## 🎨 代码风格

- 控制器层（Controller）：保持简洁，每个方法 5-10 行
- 服务层（Service）：业务逻辑中心，使用事务，抛出异常
- 仓储层（Repository）：与 Model 一一对应，统一负责对应表的数据库访问
- 模型层（Model）：专注单表映射、关联、访问器和作用域
- 请求校验层（Request）：与 Controller 按实体一一对应，并按路由动作承载全部验证规则
- 响应助手（ResponseHelper）：统一 API 响应格式
