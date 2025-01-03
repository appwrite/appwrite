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

    //Tmp
    public static array $knownMissingDescriptions = [];

    public static array $exceptions = [];

    /**
     * @var array<Multiplex>
     */
    protected array $multiplexRoutes = [];

    /**
     * Initialise a new SDK method
     *
     * @param string $namespace
     * @param string $name
     * @param string $description
     * @param array<AuthType> $auth
     * @param array<Response> $responses
     * @param int $responseCode
     * @param string|array<string> $responseModel
     * @param ResponseType $responseType
     * @param MethodType|null $methodType
     * @param string|null $offlineKey
     * @param string|null $offlineModel
     * @param string|null $offlineResponseKey
     * @param bool $deprecated
     * @param array|bool $hide
     * @param bool $packaging
     * @param string $requestType
     * @param array $parameters
     *
     * @throws \Exception
     */
    public function __construct(
        protected string $namespace,
        protected string $name,
        protected string $description,
        protected array $auth,
        protected array $responses,
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
        protected array $multiplex = []
    ) {
        $this->validateMethod($name, $namespace);
        $this->validateAuthTypes($auth);
        $this->validateDesc($description);

        foreach ($responses as $response) {
            /** @var \Appwrite\SDK\Response $response */
            $this->validateResponseModel($response->getModel());

            // No content check
            if ($response->getCode() === 204) {
                if ($response->getModel() !== Response::MODEL_NONE) {
                    self::$exceptions[] = "Error with {$this->getDebugName()} method: Response code 204 must have response model 'none'";
                }
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
            self::$exceptions[] = "Error with {$this->getDebugName()} method: Method already exists in namespace {$namespace}";
        }

        self::$knownMethods[] = $this->getDebugName();
    }

    private function validateAuthTypes(array $authTypes): void
    {
        foreach ($authTypes as $authType) {
            if (!($authType instanceof AuthType)) {
                self::$exceptions[] = "Error with {$this->getDebugName()} method: Invalid auth type";
            }
        }
    }

    private function validateDesc(string $desc): void
    {
        if (empty($desc)) {
            self::$exceptions[] = "Error with {$this->getDebugName()} method: Description label is empty";
            return;
        }

        $descPath = \realpath(__DIR__ . '/../../../' . $desc);

        if (!\file_exists($descPath)) {
            self::$exceptions[] = "Error with {$this->getDebugName()} method: Description file not found at {$desc}";
            return;
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
                    self::$exceptions[] = "Error with {$this->getDebugName()} method: Invalid response model, make sure the model has been defined in Response.php";
                }
            }

            return;
        }

        try {
            $response->getModel($responseModel);
        } catch (\Exception $e) {
            self::$exceptions[] = "Error with {$this->getDebugName()} method: Invalid response model, make sure the model has been defined in Response.php";
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

    public function getResponses(): array
    {
        return $this->responses;
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

    public function getMultiplex(): array
    {
        return $this->multiplex;
    }

    // Throw any errors that were found during initialization
    static function finaliseInitialization(): void
    {
        if (!empty(self::$exceptions)) {
            throw new \Exception('Errors found during SDK initialization:' . PHP_EOL . implode(PHP_EOL, self::$exceptions));
        }
    }
}
