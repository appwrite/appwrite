<?php

namespace Appwrite\Logs;

enum Resource: string
{
    case Project = 'project';
    case Deployment = 'deployment';
}
