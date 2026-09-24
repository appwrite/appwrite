<?php

namespace Utopia\Query\Schema\Column;

use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\Column\Trait\Comment;
use Utopia\Query\Schema\Forwarder;
use Utopia\Query\Schema\Table;

/**
 * @extends Column<Table\MongoDB>
 */
class MongoDB extends Column
{
    use Comment;
    use Forwarder\MongoDB;
}
