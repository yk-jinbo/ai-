# Controller 层 Demo

## 📝 示例文件
- `DemoProductController.php` - 完整的 RESTful API Controller 示例

## 🎯 核心职责
Controller 层是**HTTP 入口层**，负责：
- 接收 HTTP 请求
- 调用 Service 处理业务
- 返回 HTTP 响应

## ⚠️ 核心原则
- ✅ 每个方法 5-10 行代码
- ✅ 使用 `ResponseHelper::success()` / `error()`
- ✅ 使用 FormRequest 验证
- ✅ 每个 Controller 只对应一个同实体 Request，全部路由动作注入该 Request
- ✅ 使用依赖注入
- ❌ 不写业务逻辑
- ❌ 不直接操作 Model

## 📚 完整文档
详细说明请查看：[01_Controller层说明.md](../../../../docs/architecture/01_Controller层说明.md)

## 🚀 快速使用
1. 查看 `DemoProductController.php` 了解标准写法
2. 复制作为模板
3. 创建同实体 Request，并为 Controller 每个动作配置 `rulesFor{Action}()`
4. 删除 `Demo` 前缀
