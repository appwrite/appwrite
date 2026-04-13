<?php

namespace Appwrite\SDK;

enum MethodType: string
{
    case WEBAUTH = 'webAuth';
    case LOCATION = 'location';
    case GRAPHQL = 'graphql';
    case UPLOAD = 'upload';
}
