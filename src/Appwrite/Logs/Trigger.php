<?php

namespace Appwrite\Logs;

enum Trigger: string
{
    case Api = 'api';
    case Http = 'http';
    case Schedule = 'schedule';
    case Event = 'event';
}
