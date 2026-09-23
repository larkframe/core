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

> **注意（心跳阻塞边界）**：心跳检查（`select 1` / `ping`）是同步网络 IO，
> 在事件循环定时器回调中执行，每次心跳会短暂阻塞事件循环（连接数 × 网络延迟）。
> 默认 `heartbeat_interval = 50s` 且探针极快，影响可忽略；高频心跳需自行评估。

> **注意（DestructionWatcher）**：`onDestroy` 回调闭包不得捕获其挂载的
> context 对象自身，否则形成循环引用，回调触发时机退化为 `gc_collect_cycles()`，
> 连接归还延迟不可控。

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

### 超时语义

- **非 Fiber 上下文**：`pop($timeout)` / `push($data, $timeout)` 超时后返回 `false`（usleep 轮询）
- **Fiber 上下文**：挂起协程向 `Worker::$globalEvent` 注册一次性超时定时器，
  deadline 到达后被唤醒并返回 `false`——池耗尽时 `Pool::get()` 会在
  `wait_timeout` 秒后抛 `PoolException` 而非永久挂起
- **边界**：无事件循环的自管 Fiber 调度器场景无法注册定时器，
  挂起协程只能被后续 push/pop/close 唤醒，timeout 不生效
- `pop` 超时/关闭均返回 `false`，可用 `isClosed()` 区分是超时还是通道已关闭
- rendezvous 模式（容量 0）`push` 超时会撤回已入队数据，不会产生"幽灵"消息
