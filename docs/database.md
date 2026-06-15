# 数据库 (Db)

`LarkFrame\Db` 基于 Illuminate Database，提供查询构建器和 Eloquent ORM。

## 查询构建器

```php
use LarkFrame\Db;

// SELECT
$users = Db::table('users')->where('status', 'active')->get();
$user = Db::table('users')->where('id', 1)->first();

// INSERT
Db::table('users')->insert(['name' => 'John', 'email' => 'john@example.com']);
$id = Db::table('users')->insertGetId(['name' => 'John']);

// UPDATE
Db::table('users')->where('id', 1)->update(['name' => 'Jane']);

// DELETE
Db::table('users')->where('id', 1)->delete();
```

## 多数据库切换

```php
// 切换连接
Db::use('mysql')->table('users')->get();
Db::use('order_db')->table('orders')->get();
Db::use('mysql_slave')->table('reports')->get();
```

配置：

```php
// config/database.php
'database' => [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'myapp',
            'username' => 'root',
            'password' => '',
        ],
        'mysql_slave' => [
            'driver' => 'mysql',
            'host' => '10.0.0.2',
            'database' => 'myapp',
            'username' => 'reader',
            'password' => '',
        ],
        'order_db' => [
            'driver' => 'mysql',
            'host' => '10.0.0.3',
            'database' => 'orders',
            'username' => 'root',
            'password' => '',
        ],
    ],
]
```

## Eloquent Model

```php
use LarkFrame\Database\Model;

class User extends Model
{
    protected $table = 'users';
    protected $fillable = ['name', 'email'];
}

// 使用
$users = User::where('status', 'active')->get();
$user = User::find(1);
$user = User::create(['name' => 'John', 'email' => 'john@example.com']);
```

## 事务

```php
Db::transaction(function () {
    Db::table('users')->insert(['name' => 'John']);
    Db::table('logs')->insert(['action' => 'user_created']);
});

// 手动事务
Db::beginTransaction();
try {
    Db::table('users')->insert(['name' => 'John']);
    Db::commit();
} catch (\Throwable $e) {
    Db::rollBack();
}
```

## 原始 SQL

```php
Db::select('SELECT * FROM users WHERE id = ?', [1]);
Db::insert('INSERT INTO users (name) VALUES (?)', ['John']);
Db::update('UPDATE users SET name = ? WHERE id = ?', ['Jane', 1]);
Db::delete('DELETE FROM users WHERE id = ?', [1]);
```
