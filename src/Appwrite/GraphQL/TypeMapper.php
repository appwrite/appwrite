<?php

namespace Appwrite\GraphQL;

use Appwrite\Auth\Validator\Password;
use Appwrite\Event\Validator\Event;
use Appwrite\Network\Validator\CNAME;
use Appwrite\Network\Validator\Domain;
use Appwrite\Network\Validator\Email;
use Appwrite\Network\Validator\Host;
use Appwrite\Network\Validator\IP;
use Appwrite\Network\Validator\Origin;
use Appwrite\Network\Validator\URL;
use Appwrite\Task\Validator\Cron;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries;
use Appwrite\Utopia\Database\Validator\Queries\Base;
use Appwrite\Utopia\Database\Validator\Queries\Buckets;
use Appwrite\Utopia\Database\Validator\Queries\Collections;
use Appwrite\Utopia\Database\Validator\Queries\Databases;
use Appwrite\Utopia\Database\Validator\Queries\Deployments;
use Appwrite\Utopia\Database\Validator\Queries\Documents;
use Appwrite\Utopia\Database\Validator\Queries\Executions;
use Appwrite\Utopia\Database\Validator\Queries\Files;
use Appwrite\Utopia\Database\Validator\Queries\Functions;
use Appwrite\Utopia\Database\Validator\Queries\Memberships;
use Appwrite\Utopia\Database\Validator\Queries\Projects;
use Appwrite\Utopia\Database\Validator\Queries\Teams;
use Appwrite\Utopia\Database\Validator\Queries\Users;
use Appwrite\Utopia\Database\Validator\Queries\Variables;
use Appwrite\Utopia\Response\Model\Attribute;
use Exception;
use GraphQL\Type\Definition\Type;
use Utopia\App;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\Roles;
use Utopia\Database\Validator\UID;
use Utopia\Route;
use Utopia\Storage\Validator\File;
use Utopia\Validator;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Boolean;
use Utopia\Validator\FloatValidator;
use Utopia\Validator\HexColor;
use Utopia\Validator\Integer;
use Utopia\Validator\JSON;
use Utopia\Validator\Numeric;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class TypeMapper
{
    /**
     * Map a {@see Route} parameter to a GraphQL Type
     *
     * @param App $utopia
     * @param Validator|callable $validator
     * @param bool $required
     * @param array $injections
     * @return Type
     * @throws Exception
     */
    public static function fromRouteParameter(
        App $utopia,
        Validator|callable $validator,
        bool $required,
        array $injections
    ): Type {
        $validator = \is_callable($validator)
            ? \call_user_func_array($validator, $utopia->getResources($injections))
            : $validator;

        switch ((!empty($validator)) ? $validator::class : '') {
            case CNAME::class:
            case Cron::class:
            case CustomId::class:
            case Domain::class:
            case Email::class:
            case Event::class:
            case HexColor::class:
            case Host::class:
            case IP::class:
            case Key::class:
            case Origin::class:
            case Password::class:
            case Text::class:
            case UID::class:
            case URL::class:
            case WhiteList::class:
            default:
                $type = Type::string();
                break;
            case Authorization::class:
            case Base::class:
            case Buckets::class:
            case Collections::class:
            case Databases::class:
            case Deployments::class:
            case Documents::class:
            case Executions::class:
            case Files::class:
            case Functions::class:
            case Memberships::class:
            case Permissions::class:
            case Projects::class:
            case Queries::class:
            case Roles::class:
            case Teams::class:
            case Users::class:
            case Variables::class:
                $type = Type::listOf(Type::string());
                break;
            case Boolean::class:
                $type = Type::boolean();
                break;
            case ArrayList::class:
                /** @noinspection PhpPossiblePolymorphicInvocationInspection */
                $type = Type::listOf(self::fromRouteParameter(
                    $utopia,
                    $validator->getValidator(),
                    $required,
                    $injections
                ));
                break;
            case Integer::class:
            case Numeric::class:
            case Range::class:
                $type = Type::int();
                break;
            case FloatValidator::class:
                $type = Type::float();
                break;
            case Assoc::class:
            case JSON::class:
                $type = TypeRegistry::json();
                break;
            case File::class:
                $type = TypeRegistry::inputFile();
                break;
        }

        if ($required) {
            $type = Type::nonNull($type);
        }

        return $type;
    }

    /**
     * Map an {@see Attribute} to a GraphQL Type
     *
     * @param string $type
     * @param bool $array
     * @param bool $required
     * @return Type
     * @throws Exception
     */
    public static function fromCollectionAttribute(string $type, bool $array, bool $required): Type
    {
        if ($array) {
            return Type::listOf(self::fromCollectionAttribute($type, false, $required));
        }

        $type = match ($type) {
            'boolean' => Type::boolean(),
            'integer' => Type::int(),
            'double' => Type::float(),
            default => Type::string(),
        };

        if ($required) {
            $type = Type::nonNull($type);
        }

        return $type;
    }
}
