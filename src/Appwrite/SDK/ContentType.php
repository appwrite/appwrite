<?php

namespace Appwrite\SDK;

enum ContentType: string
{
    case NONE = '';
    case JSON = 'application/json';
    case IMAGE = 'image/*';
    case IMAGE_PNG = 'image/png';
    case MULTIPART = 'multipart/form-data';
    case HTML = 'text/html';
    case TEXT = 'text/plain';
    case ANY = '*/*';
}
