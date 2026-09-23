<?php

namespace LarkFrame;

/**
 * 应用层上下文入口。onDestroy 等能力由 Coroutine\Context 提供并直接继承，
 * 此前存在与父类逐行相同的冗余重写，已删除。
 */
class Context extends Coroutine\Context
{
}
