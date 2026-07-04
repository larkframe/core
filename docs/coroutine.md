# 协程 (Coroutine)

Lark 基于 PHP Fiber 实现协程支持，提供上下文隔离、连接池和内存通道。

## 上下文 (Context)

`LarkFrame\Context` 基于 Fiber + WeakMap 实现请求级上下文隔离：

```php
use LarkFrame\Context;

// 设置
Context::set('user_id', 1);

// 获取
$userId = Context::get('user_id');
$all = Context::get();  // 所有上下文数据

// 判断存在
Context::has('user_id');

// 销毁（请求结束时自动调用）
Context::destroy();

// 注册销毁回调
Context::onDestroy(function () {
    // 清理资源
});
```

在 Server 模式下，每个请求有独立的上下文，互不干扰。

## 连接池 (Pool)

`LarkFrame\Coroutine\Pool` 提供通用连接池实现：

```php
use LarkFrame\Coroutine\Pool;

$pool = Pool::fromConfig(10, [
    'min_connections' => 2,
    'idle_timeout' => 60,
    'heartbeat_interval' => 50,
]);

$pool->setConnectionCreator(function () {
    return new PDO($dsn, $user, $pass);
});

$pool->setConnectionCloser(function ($conn) {
    $conn = null;
});

// 获取连接
$conn = $pool->get();

// 归还连接
$pool->put($conn);
```

### 维护机制

- **空闲回收**：超过 `idle_timeout` 的空闲连接自动关闭
- **心跳检测**：定期检查连接可用性
- **最小连接数**：保持 `min_connections` 个连接存活

### WeakMap 遍历安全

`Pool::closeConnections()` 和 `Context::gc()` 在遍历 WeakMap 时会修改其结构（移除条目）。PHP 中直接在 `foreach` 内 `unset` WeakMap 条目会导致迭代器跳过元素。这两个方法均先将 keys 复制到普通数组，再遍历数组执行移除操作，确保所有条目都被处理。

`Context::gc()` 在清理完成后调用 `gc_collect_cycles()`，强制回收孤立的 `onDestroy` 对象并触发 `DestructionWatcher` 回调。

## 内存通道 (MemoryChannel)

`LarkFrame\Coroutine\MemoryChannel` 基于 SplQueue 实现的协程安全通道：

```php
use LarkFrame\Coroutine\MemoryChannel;

$channel = new MemoryChannel(100);  // 容量 100

$channel->push($data);     // 推入
$data = $channel->pop();   // 弹出
$len = $channel->length(); // 当前长度
```
