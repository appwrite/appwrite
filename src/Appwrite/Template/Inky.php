<?php

namespace Appwrite\Template;

use Appwrite\Template\Template;

class Inky extends Template
{
    /**
     * Inky Transpiler.
     *
     * Transpiles Inky HTML Format into generic HTML for Emails.
     *
     * @return string
     *
     */
    public function transpileInky()
    {
        $transpiled = \Pinky\transformString($this->render());
        return $transpiled->saveHTML();
    }
}
