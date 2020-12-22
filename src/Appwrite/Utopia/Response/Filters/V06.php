<?php

namespace Appwrite\Utopia\Response\Filter;

use Appwrite\Utopia\Response\Filter;

class V06 extends Filter {
    

    // Convert 0.7 Data format to 0.6 format
    public function parse(array $content): array {
        return array();
    }
}