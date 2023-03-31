<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Attribute\Name;
use Appwrite\Utopia\Response\Attribute\Options;
use Appwrite\Utopia\Response\Attribute\Type;
use Appwrite\Utopia\Response\Model;
use Appwrite\Utopia\Response\Rule;
use DateTime;

#[Name('Platform')]
#[Type(Response::MODEL_PLATFORM)]
#[Options(public: false)]
class Platform extends Model
{
    #[Rule(
        description: 'Platform ID.',
        default: '',
        example: '5e5ea5c16897e'
    )]
    public string $id;

    #[Rule(
        description: 'Platform creation date in ISO 8601 format.',
        default: '',
        example: self::TYPE_DATETIME_EXAMPLE
    )]
    public DateTime $createdAt;

    #[Rule(
        description: 'Platform update date in ISO 8601 format.',
        default: '',
        example: self::TYPE_DATETIME_EXAMPLE
    )]
    public DateTIme $updatedAt;

    #[Rule(
        description: 'Platform name.',
        default: '',
        example: 'My Web App'
    )]
    public string $name;

    #[Rule(
        description: 'Platform description.',
        default: '',
        example: 'My Web App description'
    )]
    public string $description;

    #[Rule(
        description: 'Platform type. Possible values are: web, flutter-web, flutter-ios, flutter-android, ios, android, and unity.',
        default: '',
        example: 'web'
    )]
    public string $type;

    #[Rule(
        description: 'Platform Key. iOS bundle ID or Android package name.  Empty string for other platforms.',
        default: '',
        example: 'com.company.appname'
    )]
    public string $key;

    #[Rule(
        description: 'Platform store. Possible values are: google, apple, and microsoft.',
        default: '',
        example: 'google'
    )]
    public string $store;

    #[Rule(
        description: 'Web app hostname. Empty string for other platforms.',
        default: '',
        example: 'localhost'
    )]
    public string $hostname;
}
