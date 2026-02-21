<?php

namespace Appwrite\SDK;

use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Swoole\Http\Response as HttpResponse;

class Method
{
    public static array $processed = [];

    public static array $errors = [];

    /**
     * Initialise a new SDK method
     *
     * @param string $namespace
     * @param ?string $group
     * @param string $name
     * @param string $desc
     * @param string $description
     * @param array<AuthType> $auth
     * @param array<SDKResponse> $responses
     * @param ContentType $contentType
     * @param MethodType|null $type
     * @param Deprecated|null $deprecated
     * @param array|bool $hide
     * @param bool $packaging
     * @param ContentType $requestType
     * @param array<Parameter> $parameters
     * @param array $additionalParameters
     * @param string $desc
     * @param bool $public Whether this method should be rendered on the website/documentation
     */
    public function __construct(
        protected string $namespace,
        protected ?string $group,
        protected string $name,
        protected string $description,
        protected array $auth,
        protected array $responses,
        protected ContentType $contentType = ContentType::JSON,
        protected ?MethodType $type = null,
        protected ?Deprecated $deprecated = null,
        protected array|bool $hide = false,
        protected bool $packaging = false,
        protected ContentType $requestType = ContentType::JSON,
        protected array $parameters = [],
        protected array $additionalParameters = [],
        protected string $desc = '',
        protected bool $public = true
    ) {
        $this->validateMethod($name, $namespace);
        $this->validateAuthTypes($auth);
        $this->validateDesc($description);

        foreach ($responses as $response) {
            $this->validateResponseModel($response->getModel());
            $this->validateNoContent($response);
        }
    }

    protected function getRouteName(): string
    {
        return $this->namespace . '.' . $this->name;
    }

    protected function validateMethod(string $name, string $namespace): void
    {
        if (\in_array($this->getRouteName(), self::$processed)) {
            self::$errors[] = "Error with {$this->getRouteName()} method: Method already exists in namespace {$namespace}";
        }

        self::$processed[] = $this->getRouteName();
    }

    protected function validateAuthTypes(array $authTypes): void
    {
        foreach ($authTypes as $authType) {
            if (!($authType instanceof AuthType)) {
                self::$errors[] = "Error with {$this->getRouteName()} method: Invalid auth type";
            }
        }
    }

    protected function validateDesc(string $desc): void
    {
        if (empty($desc)) {
            self::$errors[] = "Error with {$this->getRouteName()} method: Description label is empty";
            return;
        }

        if (\str_ends_with($desc, '.md')) {
            $descPath = $this->getDescriptionFilePath() ?: $this->getDescription();

            if (empty($descPath)) {
                self::$errors[] = "Error with {$this->getRouteName()} method: Description file not found at {$desc}";
                return;
            }
        }
    }

    protected function validateResponseModel(string|array $responseModel): void
    {
        $response = new Response(new HttpResponse());

        if (!\is_array($responseModel)) {
            $responseModel = [$responseModel];
        }

        foreach ($responseModel as $model) {
            try {
                $response->getModel($model);
            } catch (\Exception $e) {
                self::$errors[] = "Error with {$this->getRouteName()} method: Invalid response model, make sure the model has been defined in Response.php";
            }
        }
    }

    protected function validateNoContent(SDKResponse $response): void
    {
        if ($response->getCode() === 204) {
            if ($response->getModel() !== Response::MODEL_NONE) {
                self::$errors[] = "Error with {$this->getRouteName()} method: Response code 204 must have response model 'none'";
            }
        }
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function getGroup(): ?string
    {
        return $this->group;
    }

    public function getMethodName(): string
    {
        return $this->name;
    }

    public function getDesc(): string
    {
        return $this->desc;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * This method returns the absolute path to the description file returning null if the file does not exist.
     *
     * @return string|null
     */
    public function getDescriptionFilePath(): ?string
    {
        return \realpath(__DIR__ . '/../../../' . $this->getDescription()) ?: null;
    }

    public function getAuth(): array
    {
        return $this->auth;
    }

    /**
     * @return array<SDKResponse>
     */
    public function getResponses(): array
    {
        return $this->responses;
    }

    public function getContentType(): ContentType
    {
        return $this->contentType;
    }

    public function getType(): ?MethodType
    {
        return $this->type;
    }

    public function isDeprecated(): bool
    {
        return $this->deprecated !== null;
    }

    public function getDeprecated(): ?Deprecated
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

    public function getRequestType(): ContentType
    {
        return $this->requestType;
    }

    /**
     * @return array<Parameter>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getAdditionalParameters(): array
    {
        return $this->additionalParameters;
    }

    public function setNamespace(string $namespace): self
    {
        $this->namespace = $namespace;
        return $this;
    }

    public function setMethodName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function setDesc(string $desc): self
    {
        $this->desc = $desc;
        return $this;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function setAuth(array $auth): self
    {
        $this->validateAuthTypes($auth);
        $this->auth = $auth;
        return $this;
    }

    /**
     * @param array<SDKResponse> $responses
     */
    public function setResponses(array $responses): self
    {
        foreach ($responses as $response) {
            $this->validateResponseModel($response->getModel());
            $this->validateNoContent($response);
        }
        $this->responses = $responses;
        return $this;
    }

    public function setContentType(ContentType $contentType): self
    {
        $this->contentType = $contentType;
        return $this;
    }

    public function setType(?MethodType $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function setDeprecated(bool|Deprecated $deprecated): self
    {
        $this->deprecated = $deprecated;
        return $this;
    }

    public function setHide(bool|Deprecated $hide): self
    {
        $this->hide = $hide;
        return $this;
    }

    public function setPackaging(bool $packaging): self
    {
        $this->packaging = $packaging;
        return $this;
    }

    public function setRequestType(ContentType $requestType): self
    {
        $this->requestType = $requestType;
        return $this;
    }

    public function setParameters(array $parameters): self
    {
        $this->parameters = $parameters;
        return $this;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    public function setPublic(bool $public): self
    {
        $this->public = $public;
        return $this;
    }

    public static function getErrors(): array
    {
        return self::$errors;
    }
}
