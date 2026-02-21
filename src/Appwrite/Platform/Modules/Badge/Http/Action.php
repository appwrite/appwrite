<?php

namespace Appwrite\Platform\Modules\Badge\Http;

use Utopia\Platform\Action as UtopiaAction;

abstract class Action extends UtopiaAction
{
    protected const LABEL_FONT_SIZE = 110;
    protected const LABEL_TEXT_X = 260;
    protected const TEXT_PADDING = 10;
    protected const FALLBACK_CHAR_WIDTH = 7;
    protected const COLOR_BRIGHTGREEN = '#4c1';
    protected const COLOR_GREEN = '#97ca00';
    protected const COLOR_YELLOW = '#dfb317';
    protected const COLOR_YELLOWGREEN = '#a4a61d';
    protected const COLOR_ORANGE = '#fe7d37';
    protected const COLOR_RED = '#e05d44';
    protected const COLOR_BLUE = '#007ec6';
    protected const COLOR_LIGHTGREY = '#9f9f9f';
    protected const COLOR_GREY = '#555';
    protected const COLOR_BY_NAME = [
        'brightgreen' => self::COLOR_BRIGHTGREEN,
        'green' => self::COLOR_GREEN,
        'yellow' => self::COLOR_YELLOW,
        'yellowgreen' => self::COLOR_YELLOWGREEN,
        'orange' => self::COLOR_ORANGE,
        'red' => self::COLOR_RED,
        'blue' => self::COLOR_BLUE,
        'lightgrey' => self::COLOR_LIGHTGREY,
        'grey' => self::COLOR_GREY,
    ];
}
