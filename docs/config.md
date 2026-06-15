# 配置 (Config)

`LarkFrame\Config` 管理应用配置，支持环境变量和多环境配置。

## 读取配置

```php
use LarkFrame\Config;

// 通过辅助函数
$value = config('app.name');
$value = config('app.debug', false);  // 带默认值
$all = config();  // 所有配置

// 通过 Config 类
$value = Config::get('database.default');
```

## 配置文件

配置文件位于 `config/config.php`，支持环境覆盖：

```
config/
  config.php           # 主配置
  config.dev.php       # 开发环境覆盖
  config.test.php      # 测试环境覆盖
  config.prod.php      # 生产环境覆盖
```

环境配置会与主配置合并（`array_merge`），环境配置优先。

## 环境变量 (.env)

`.env` 文件位于项目根目录，**仅用于定义应用启动参数**，其值不会注入到 `config()` 配置系统中：

```ini
APP_NAME=myapp
TIME_ZONE=Asia/Shanghai
RUN_MODE=prod
```

框架在 `App::run()` 时读取 `.env`，将其中的值定义为 PHP 常量：
- `APP_NAME` → `APP_NAME` 常量
- `TIME_ZONE` → 用于 `date_default_timezone_set()`
- `RUN_MODE` → `RUN_MODE` 常量（dev/test/stage/prod）

如果 `.env` 文件不存在，框架会自动创建一个包含默认值的文件。敏感配置（数据库密码、Redis 密码等）应通过 `config/config.{env}.php` 环境覆盖文件管理。

支持的数据类型：
- 布尔值：`true`/`false`
- 空值：`null`
- 数字：`123`、`3.14`
- 字符串：`hello`、`"quoted value"`

## 配置加载流程

1. `App::run()` 调用 `Config::loadEnv()` 加载 `.env`（不存在则自动创建）
2. 根据 `.env` 设置时区、`RUN_MODE` 常量
3. `Config::load()` 加载 `config/config.php`
4. 根据 `RUN_MODE` 加载对应环境配置
5. Server 模式下 `onWorkerStart` 会重新加载配置
