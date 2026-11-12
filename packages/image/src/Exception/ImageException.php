<?php

namespace Utopia\Image\Exception;

use Exception;

/**
 * What an image, or the system working on it, makes fail. The subclasses say
 * which, so a caller can answer each differently; this class itself is an
 * operation, an encoder or a write that failed.
 *
 * An argument that could never work (a focal point outside the image, an
 * unknown colour, a negative size) is an \InvalidArgumentException instead.
 */
class ImageException extends Exception
{
}
