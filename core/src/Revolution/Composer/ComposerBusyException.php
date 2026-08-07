<?php

namespace MODX\Revolution\Composer;

use RuntimeException;

/**
 * Thrown when a composer job is submitted while another job is still pending
 * or running. Only one composer mutation may run against the project root at
 * a time, and the guard fires at submit time so callers get an immediate,
 * user-presentable "busy" answer instead of a worker that fails later on the
 * project lock.
 */
class ComposerBusyException extends RuntimeException
{
}
