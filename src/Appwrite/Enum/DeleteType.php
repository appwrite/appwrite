<?php

namespace Appwrite\Enum;

/**
 * Type of delete event the Deletes worker perform
 */
enum DeleteType: string
{
    case Databases = 'databases';
    case Document = 'document';
    case Collections = 'collections';
    case Projects = 'projects';
    case Functions = 'functions';
    case Deployments = 'deployments';
    case Users = 'users';
    case Teams = 'teams';
    case Executions = 'executions';
    case Audit = 'audit';
    case Abuse = 'abuse';
    case Usage = 'usage';
    case Realtime = 'realtime';
    case Buckets = 'buckets';
    case Installations = 'installations';
    case Rules = 'rules';
    case Sessions = 'sessions';
    case CacheByTimestamp = 'cachebytimestamp';
    case CacheByResource  = 'cachebyresource';
    case Schedules = 'schedules';
    case Topic = 'topic';
    case Target = 'target';
}
