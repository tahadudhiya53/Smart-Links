<?php

namespace Tahadudhiya\SmartLinks\errors;

use RuntimeException;

/**
 * The link index could not be written because another process held its lock for longer than the
 * write was willing to wait. Nothing was written; the write can be retried.
 */
class IndexLockedException extends RuntimeException
{
}
