<?php

namespace Utopia\Query;

enum CursorDirection: string
{
    case After = 'after';
    case Before = 'before';
}
