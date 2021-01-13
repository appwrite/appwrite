<?php

namespace Appwrite\Migration\Version;

use Utopia\CLI\Console;
use Appwrite\Migration\Migration;

class V06 extends Migration
{
  public function execute(): void
  {
    Console::log('I got nothing to do. Yet.');

    //TODO: migrate new `filter` property

  }
}
