# Request 层 Demo

## 📝 示例文件

- `DemoProductRequest.php`：与 `DemoProductController.php` 一一对应，承载该 Controller 全部路由动作的校验规则。

## 🎯 核心职责

Request 层负责请求参数验证、授权检查、中文错误消息和字段名称，不承载业务逻辑或数据库访问。

## ⚠️ 核心原则

- 每个资源 Controller 只对应一个同实体 Request。
- Controller 的全部路由动作都注入该 Request。
- `rules()` 根据当前 Controller 方法分发到 `rulesFor{Action}()`。
- 无参数动作也要显式定义空规则；未知动作抛 `LogicException`，禁止静默放行。
- 动作规则方法不得直接命名为 `user()`、`route()`、`input()` 等 Laravel Request 已有方法。
- 更新唯一性校验使用 `Rule::unique()->ignore()`。
- Controller 不写验证逻辑，Request 不写业务逻辑。

## 📚 完整文档

详细说明请查看：[05_其他层说明.md](../../../docs/architecture/05_其他层说明.md#请求校验层request)。

## 🚀 快速使用

1. 创建与 Controller 同实体的 Request，例如 `ProductController` 对应 `ProductRequest`。
2. 在 `rules()` 中登记 Controller 的全部路由动作。
3. 为每个动作实现 `rulesForIndex()`、`rulesForStore()` 等规则方法。
4. 添加中文 `messages()` 与 `attributes()`，并确认表名和路由参数一致。
