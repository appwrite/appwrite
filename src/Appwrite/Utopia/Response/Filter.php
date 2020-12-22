<?php

namespace Appwrite\Utopia\Response;

abstract class Filter {

    /**
     * Parse the content to another format.
     *
     * @param array $content
     *
     * @return array
     */
    abstract function parse(array $content): array;
    
}