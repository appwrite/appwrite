<?php

namespace Appwrite\Utopia;

use Utopia\View as OldView;

class View extends OldView
{
    /**
     * Escape
     *
     * Convert all applicable characters to HTML entities
     *
     * @param  string  $str
     * @return string
     *
     * @deprecated Use print method with escape filter
     */
    public function escape($str)
    {
        return \htmlentities($str, ENT_QUOTES, 'UTF-8');
    }
}
