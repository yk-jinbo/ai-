# Service 层 Demo

## 📝 示例文件
- `DemoProductService.php` - 完整的 Service 示例

## 🎯 核心职责
Service 层是**核心业务逻辑层**，负责：
- 业务流程编排
- 事务管理
- 业务规则验证
- 调用 Repository/Model

## ⚠️ 核心原则
- ✅ 包含所有业务逻辑
- ✅ 使用 `DB::beginTransaction()` 管理事务
- ✅ 使用 `ResponseHelper::errorIf()` 验证规则
- ✅ 抛出异常，返回 Model 对象
- ❌ 不处理 HTTP 请求/响应

## 📚 完整文档
详细说明请查看：[02_Service层说明.md](../../docs/architecture/02_Service层说明.md)

## 🚀 快速使用
1. 查看 `DemoProductService.php` 了解标准写法
2. 复制作为模板
3. 修改类名和依赖注入
4. 实现业务逻辑
5. 删除 `Demo` 前缀
