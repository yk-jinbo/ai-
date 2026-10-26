# Model 层 Demo

## 📝 示例文件
- `DemoProductModel.php` - 完整的 Model 示例

## 🎯 核心职责
Model 层负责：
- 数据表映射
- 关联关系定义
- 访问器/修改器
- 查询作用域

双数据库时必须显式声明连接：主库使用 `protected $connection = 'mysql'`，第二库使用 `protected $connection = 'data_bridge'`。连接名固定在 Model，不从请求参数传入；`data_bridge` 的表前缀由 `DATA_BRIDGE_DB_PREFIX` 配置。

## 📚 完整文档
详细说明请查看：[03_Model层说明.md](../../docs/architecture/03_Model层说明.md)

## 🚀 快速使用
1. 查看 `DemoProductModel.php` 了解标准写法
2. 复制作为模板
3. 声明所属连接（`mysql` 或 `data_bridge`）
4. 修改类名、表名、字段
5. 删除 `Demo` 前缀
