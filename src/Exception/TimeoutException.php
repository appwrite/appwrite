<?php

declare(strict_types=1);

namespace Utopia\SMTP\Exception;

/**
 * A deadline passed with the other end still silent.
 *
 * A timeout is a connection failure a caller may want to treat
 * differently: it says nothing about whether the server is healthy, only that
 * it did not answer in time.
 */
class TimeoutException extends ConnectionException {}
