# Repository 层 Demo

## 📝 示例文件
- `DemoProductRepository.php` - 完整的 Repository 示例

## 🎯 核心职责
Repository 层负责：
- 封装所有数据库访问（包括简单 CRUD）
- 隔离 Service 与 Model
- 返回模型、集合或分页结果

双数据库时，Repository 通过注入 Model 固定访问连接：Model 的 `$connection` 为 `mysql` 时访问主库，为 `data_bridge` 时访问第二库。Repository 不接收连接名参数，不动态切换连接；跨库流程由 Service 注入两个独立 Repository 编排。

## ⚠️ 使用边界
- ✅ 每个正式业务实体都应有 Repository
- ✅ 复杂查询在 Repository 内组装
- ❌ Service/Controller 直接使用 Model 或 Query Builder
- ❌ 一个 Repository 同时注入主库和 `data_bridge` 库 Model
- ❌ 通过请求参数选择数据库连接
- ❌ 在 Repository 内拼接 Redis key；Service 通过 `CacheManager` 取得 key/connection 后直接调用 Redis

## 📚 完整文档
详细说明请查看：[04_Repository层说明.md](../../docs/architecture/04_Repository层说明.md)

## 🚀 快速使用
1. 查看 `DemoProductRepository.php` 了解标准写法
2. 复制作为模板
3. 修改类名和 Model 引用
4. 添加复杂查询方法
5. 删除 `Demo` 前缀
