# Enum 层 Demo

## 📝 示例文件
- `ApiCode.php` - 通用 API 业务状态码
- `UserStatus.php` - 完整的 Enum 示例

## 🎯 核心职责
Enum 层负责：
- 定义固定的常量集合
- 避免魔法字符串
- 提供类型安全
- 提供标签和业务方法

## ⚠️ 核心原则
- ✅ 使用 Enum 替代魔法字符串
- ✅ 在 Model 的 `$casts` 中声明
- ✅ 提供 `label()` 方法返回中文
- ✅ 可添加业务方法（如 `isActive()`）

## 📚 完整文档
详细说明请查看：[05_其他层说明.md](../../docs/architecture/05_其他层说明.md#enum-层)

## 🚀 快速使用
1. 查看 `UserStatus.php` 了解标准写法
2. 复制作为模板
3. 定义枚举值（case）
4. 添加 `label()` 方法
5. 根据需要添加业务方法
