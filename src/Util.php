<?php

namespace LarkFrame;

use LarkFrame\Util\UtilFacade;

/**
 * Util 门面类，统一访问工具组件
 *
 * 用法：
 *   Util::base64()->encode($data)
 *   Util::base64()->urlEncode($data)
 *   Util::base64()->authcode($string, 'ENCODE')
 *   Util::str()->camelToUnderscore($input)
 *   Util::str()->mask('13800138000')
 *   Util::rand()->uuid()
 *   Util::rand()->str(16)
 *   Util::file()->ensureDir($dir)
 *   Util::file()->safeWrite($path, $content)
 *   Util::img()->resize($src, $dst, 100, 100)
 *   Util::mock()->list($template, 10)
 *
 * 也可以直接使用工具类：
 *   \LarkFrame\Util\Str::mask('13800138000')
 *   \LarkFrame\Util\Rand::uuid()
 */
class Util extends UtilFacade
{
}
