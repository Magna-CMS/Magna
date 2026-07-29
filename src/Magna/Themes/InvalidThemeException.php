<?php

declare(strict_types=1);

namespace Magna\Themes;

use RuntimeException;

/**
 * A theme package that cannot be trusted to be one. Messages are written for
 * an admin to read, because that is where they end up.
 */
class InvalidThemeException extends RuntimeException {}
