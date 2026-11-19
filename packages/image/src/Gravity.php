<?php

declare(strict_types=1);

namespace Utopia\Image;

/**
 * Which part of an image a crop keeps.
 */
enum Gravity: string
{
    case Center = 'center';
    case TopLeft = 'top-left';
    case Top = 'top';
    case TopRight = 'top-right';
    case Left = 'left';
    case Right = 'right';
    case BottomLeft = 'bottom-left';
    case Bottom = 'bottom';
    case BottomRight = 'bottom-right';
}
