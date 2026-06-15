<?php

namespace LarkFrame\Coroutine;

/**
 * Enum ConnectionStatus
 *
 * Represents the lifecycle status of a pooled connection.
 * Uses PHP 8.1 Enum instead of class constants for type safety.
 */
enum ConnectionStatus: int
{
    case Idle = 0;
    case Active = 1;
    case Closed = 2;
}
