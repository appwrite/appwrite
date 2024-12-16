<?php

namespace Appwrite\SDK;

use Appwrite\Utopia\Response;
use Swoole\Http\Response as HttpResponse;

enum AuthType: string
{
    case JWT = APP_AUTH_TYPE_JWT;
    case KEY = APP_AUTH_TYPE_KEY;
    case SESSION = APP_AUTH_TYPE_SESSION;
    case ADMIN = APP_AUTH_TYPE_ADMIN;
}

enum MethodType: string
{
    case WEBAUTH = 'webAuth';
    case LOCATION = 'location';
    case GRAPHQL = 'graphql';
    case UPLOAD = 'upload';
}

enum ResponseType: string
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

class Method
{
    public static array $knownMethods = [];

    /**
     * Initialise a new SDK method
     *
     * @param array<AuthType> $authTypes
     * @param string $namespace
     * @param MethodType $methodType
     * @param string $desc
     * @param int $responseCode
     * @param string $responseModel
     * @param string $offlineKey
     * @param string $offlineModel
     *
     * @throws \Exception
     */
    public function __construct(
        protected string $namespace,
        protected string $name,
        protected string $description,
        protected array $auth,
        protected int $responseCode,
        protected string|array $responseModel,
        protected ResponseType $responseType = ResponseType::JSON,
        protected ?MethodType $methodType = null,
        protected ?string $offlineKey = null,
        protected ?string $offlineModel = null,
        protected ?string $offlineResponseKey = null,
        protected bool $deprecated = false,
        protected array|bool $hide = false,
        protected bool $packaging = false,
        protected string $requestType = 'application/json',
        protected array $parameters = [],
    ) {
        $this->validateMethod($name, $namespace);
        $this->validateAuthTypes($auth);
        // Disabled for now, will be enabled later
        // $this->validateDesc($description);
        $this->validateResponseModel($responseModel);

        // No content check
        if ($responseCode === 204) {
            if ($responseModel !== Response::MODEL_NONE) {
                throw new \Exception("Error with {$this->getDebugName()} method: Response code 204 must have response model 'none'");
            }
        }
    }

    private function getDebugName(): string
    {
        return $this->namespace . '.' . $this->name;
    }

    private function validateMethod(string $name, string $namespace): void
    {
        if (\in_array($this->getDebugName(), self::$knownMethods)) {
            throw new \Exception('Method ' . $name . ' already exists in namespace ' . $namespace);
        }

        self::$knownMethods[] = $this->getDebugName();
    }

    private function validateAuthTypes(array $authTypes): void
    {
        foreach ($authTypes as $authType) {
            if (!($authType instanceof AuthType)) {
                throw new \Exception("Error with {$this->getDebugName()} method: Invalid auth type");
            }
        }
    }

    private function validateDesc(string $desc): void
    {
        if (empty($desc)) {
            throw new \Exception("Error with {$this->getDebugName()} method: Description file not set");
        }

        $descPath = \realpath(__DIR__ . '/../../../' . $desc);

        if (!\file_exists($descPath)) {
            throw new \Exception("Error with {$this->getDebugName()} method: Description file not found at {$descPath}");
        }
    }

    private function validateResponseModel(string|array $responseModel): void
    {
        $response = new Response(new HttpResponse());

        if (\is_array($responseModel)) {
            foreach ($responseModel as $model) {
                try {
                    $response->getModel($model);
                } catch (\Exception $e) {
                    throw new \Exception("Error with {$this->getDebugName()} method: Invalid response model, make sure the model has been defined in Response.php");
                }
            }

            return;
        }

        try {
            $response->getModel($responseModel);
        } catch (\Exception $e) {
            throw new \Exception("Error with {$this->getDebugName()} method: Invalid response model, make sure the model has been defined in Response.php");
        }
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function getMethodName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getAuth(): array
    {
        return $this->auth;
    }

    public function getResponseCode(): int
    {
        return $this->responseCode;
    }

    public function getResponseModel(): string|array
    {
        return $this->responseModel;
    }

    public function getResponseType(): ResponseType
    {
        return $this->responseType;
    }

    public function getMethodType(): ?MethodType
    {
        return $this->methodType;
    }

    public function getOfflineKey(): ?string
    {
        return $this->offlineKey;
    }

    public function getOfflineModel(): ?string
    {
        return $this->offlineModel;
    }

    public function getOfflineResponseKey(): ?string
    {
        return $this->offlineResponseKey;
    }

    public function isDeprecated(): bool
    {
        return $this->deprecated;
    }

    public function isHidden(): bool|array
    {
        return $this->hide ?? false;
    }

    public function isPackaging(): bool
    {
        return $this->packaging;
    }

    public function getRequestType(): string
    {
        return $this->requestType;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }
}
