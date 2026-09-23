<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Parser;

use Utopia\OpenAPI\Exception\InvalidSpecification;
use Utopia\OpenAPI\Model\Contact;
use Utopia\OpenAPI\Model\Encoding;
use Utopia\OpenAPI\Model\Example;
use Utopia\OpenAPI\Model\ExternalDocumentation;
use Utopia\OpenAPI\Model\Header;
use Utopia\OpenAPI\Model\Info;
use Utopia\OpenAPI\Model\License;
use Utopia\OpenAPI\Model\MediaType;
use Utopia\OpenAPI\Model\OAuthFlow;
use Utopia\OpenAPI\Model\ParameterLocation;
use Utopia\OpenAPI\Model\SecurityRequirement;
use Utopia\OpenAPI\Model\SecurityScheme;
use Utopia\OpenAPI\Model\SecuritySchemeType;
use Utopia\OpenAPI\Model\Server;
use Utopia\OpenAPI\Model\ServerVariable;
use Utopia\OpenAPI\Model\Tag;
use Utopia\OpenAPI\Parser\Schema\Reader as SchemaReader;
use Utopia\OpenAPI\Reference\LocalResolver;
use Utopia\OpenAPI\Version;

abstract class AbstractReader implements Reader
{
    protected LocalResolver $resolver;

    /** @param array<string, mixed> $document */
    public function __construct(
        protected readonly array $document,
        protected readonly Version $version,
        protected readonly string $sourceVersion,
        protected readonly SchemaReader $schemas,
    ) {
        $this->resolver = new LocalResolver($document);
    }

    protected function parseInfo(): Info
    {
        $data = Value::object($this->document['info'] ?? null, '#/info');
        $contact = null;
        if (isset($data['contact'])) {
            $value = Value::object($data['contact'], '#/info/contact');
            $contact = new Contact(
                name: Value::optionalString($value, 'name') ?? '',
                url: Value::optionalString($value, 'url'),
                email: Value::optionalString($value, 'email'),
                extensions: Value::extensions($value),
            );
        }

        $license = null;
        if (isset($data['license'])) {
            $value = Value::object($data['license'], '#/info/license');
            $license = new License(
                name: Value::requiredString($value, 'name', '#/info/license'),
                url: Value::optionalString($value, 'url'),
                identifier: Value::optionalString($value, 'identifier'),
                extensions: Value::extensions($value),
            );
        }

        return new Info(
            title: Value::requiredString($data, 'title', '#/info'),
            description: Value::optionalString($data, 'description') ?? '',
            version: Value::requiredString($data, 'version', '#/info'),
            termsOfService: Value::optionalString($data, 'termsOfService'),
            contact: $contact,
            license: $license,
            extensions: Value::extensions($data),
        );
    }

    /** @return array<string, Tag> */
    protected function parseTags(): array
    {
        $tags = [];
        foreach (Value::list($this->document['tags'] ?? [], '#/tags') as $index => $raw) {
            $data = Value::object($raw, "#/tags/{$index}");
            $name = Value::requiredString($data, 'name', "#/tags/{$index}");
            $tags[$name] = new Tag(
                name: $name,
                description: Value::optionalString($data, 'description') ?? '',
                externalDocumentation: isset($data['externalDocs'])
                    ? $this->parseExternalDocumentation($data['externalDocs'], "#/tags/{$index}/externalDocs")
                    : null,
                extensions: Value::extensions($data),
            );
        }

        return $tags;
    }

    protected function parseExternalDocumentation(mixed $raw, string $location): ExternalDocumentation
    {
        $data = Value::object($raw, $location);

        return new ExternalDocumentation(
            url: Value::requiredString($data, 'url', $location),
            description: Value::optionalString($data, 'description') ?? '',
            extensions: Value::extensions($data),
        );
    }

    /** @return list<Server> */
    protected function parseServers(mixed $raw, string $location): array
    {
        $servers = [];
        foreach (Value::list($raw, $location) as $index => $item) {
            $data = Value::object($item, "{$location}/{$index}");
            $variables = [];
            foreach (Value::object($data['variables'] ?? [], "{$location}/{$index}/variables") as $name => $variable) {
                $value = Value::object($variable, "{$location}/{$index}/variables/{$name}");
                $enum = isset($value['enum']) ? Value::list($value['enum'], "{$location}/{$index}/variables/{$name}/enum") : [];
                $variables[(string) $name] = new ServerVariable(
                    default: (string) ($value['default'] ?? ''),
                    enum: array_map(strval(...), $enum),
                    description: Value::optionalString($value, 'description') ?? '',
                    extensions: Value::extensions($value),
                );
            }
            $servers[] = new Server(
                url: Value::requiredString($data, 'url', "{$location}/{$index}"),
                description: Value::optionalString($data, 'description') ?? '',
                variables: $variables,
                extensions: Value::extensions($data),
            );
        }

        return $servers;
    }

    /** @return list<SecurityRequirement> */
    protected function parseSecurity(mixed $raw, string $location): array
    {
        $requirements = [];
        foreach (Value::list($raw, $location) as $index => $item) {
            $data = Value::object($item, "{$location}/{$index}");
            $schemes = [];
            foreach ($data as $name => $scopes) {
                $schemes[(string) $name] = array_map(
                    static fn(mixed $scope): string => (string) $scope,
                    Value::list($scopes, "{$location}/{$index}/{$name}"),
                );
            }
            $requirements[] = new SecurityRequirement($schemes);
        }

        return $requirements;
    }

    protected function parseMediaTypes(mixed $raw, string $location): array
    {
        $content = [];
        foreach (Value::object($raw, $location) as $name => $item) {
            $data = Value::object($item, "{$location}/{$name}");
            $examples = [];
            foreach (Value::object($data['examples'] ?? [], "{$location}/{$name}/examples") as $exampleName => $exampleRaw) {
                $example = $this->resolveObject($exampleRaw, "{$location}/{$name}/examples/{$exampleName}");
                $examples[(string) $exampleName] = new Example(
                    summary: Value::optionalString($example, 'summary') ?? '',
                    description: Value::optionalString($example, 'description') ?? '',
                    value: $example['value'] ?? null,
                    externalValue: Value::optionalString($example, 'externalValue'),
                    extensions: Value::extensions($example),
                );
            }
            $encoding = [];
            foreach (Value::object($data['encoding'] ?? [], "{$location}/{$name}/encoding") as $property => $encodingRaw) {
                $value = Value::object($encodingRaw, "{$location}/{$name}/encoding/{$property}");
                $encoding[(string) $property] = new Encoding(
                    contentType: Value::optionalString($value, 'contentType'),
                    headers: $this->parseHeaders($value['headers'] ?? [], "{$location}/{$name}/encoding/{$property}/headers"),
                    style: Value::optionalString($value, 'style'),
                    explode: \array_key_exists('explode', $value) ? (bool) $value['explode'] : null,
                    allowReserved: (bool) ($value['allowReserved'] ?? false),
                    extensions: Value::extensions($value),
                );
            }
            $content[(string) $name] = new MediaType(
                schema: \array_key_exists('schema', $data) ? $this->schemas->read($data['schema'], "{$location}/{$name}/schema") : null,
                example: $data['example'] ?? null,
                examples: $examples,
                encoding: $encoding,
                extensions: Value::extensions($data),
            );
        }

        return $content;
    }

    /** @return array<string, Header> */
    protected function parseHeaders(mixed $raw, string $location, bool $openApi2 = false): array
    {
        $headers = [];
        foreach (Value::object($raw, $location) as $name => $item) {
            $data = $this->resolveObject($item, "{$location}/{$name}");
            $schema = null;
            if (\array_key_exists('schema', $data)) {
                $schema = $this->schemas->read($data['schema'], "{$location}/{$name}/schema");
            } elseif ($openApi2 && isset($data['type'])) {
                $schema = $this->schemas->readParameterFields($data, "{$location}/{$name}");
            }
            $headers[(string) $name] = new Header(
                description: Value::optionalString($data, 'description') ?? '',
                required: (bool) ($data['required'] ?? false),
                deprecated: (bool) ($data['deprecated'] ?? false),
                schema: $schema,
                content: isset($data['content']) ? $this->parseMediaTypes($data['content'], "{$location}/{$name}/content") : [],
                style: Value::optionalString($data, 'style'),
                explode: \array_key_exists('explode', $data) ? (bool) $data['explode'] : null,
                extensions: Value::extensions($data),
            );
        }

        return $headers;
    }

    /** @return array<string, mixed> */
    protected function resolveObject(mixed $raw, string $location): array
    {
        $data = Value::object($raw, $location);
        if (isset($data['$ref'])) {
            $reference = Value::requiredString($data, '$ref', $location);
            $data = Value::object($this->resolver->resolveObject($reference), $reference);
        }

        return $data;
    }

    protected function parseSecurityScheme(array $data, string $location, bool $openApi2 = false): SecurityScheme
    {
        $type = Value::requiredString($data, 'type', $location);
        if ($openApi2 && $type === 'basic') {
            return new SecurityScheme(SecuritySchemeType::HTTP, Value::optionalString($data, 'description') ?? '', scheme: 'basic', extensions: Value::extensions($data));
        }
        if ($type === 'apiKey') {
            $locationValue = Value::requiredString($data, 'in', $location);
            try {
                $parameterLocation = ParameterLocation::from($locationValue);
            } catch (\ValueError) {
                throw new InvalidSpecification("Unsupported API key location '{$locationValue}' at {$location}/in");
            }

            return new SecurityScheme(
                SecuritySchemeType::API_KEY,
                Value::optionalString($data, 'description') ?? '',
                name: Value::requiredString($data, 'name', $location),
                location: $parameterLocation,
                extensions: Value::extensions($data),
            );
        }
        if ($type === 'oauth2') {
            $flows = $openApi2 ? $this->parseOpenApi2OAuthFlows($data, $location) : $this->parseOAuthFlows($data['flows'] ?? [], "{$location}/flows");

            return new SecurityScheme(SecuritySchemeType::OAUTH2, Value::optionalString($data, 'description') ?? '', flows: $flows, extensions: Value::extensions($data));
        }
        if ($type === 'http') {
            return new SecurityScheme(
                SecuritySchemeType::HTTP,
                Value::optionalString($data, 'description') ?? '',
                scheme: strtolower(Value::requiredString($data, 'scheme', $location)),
                bearerFormat: Value::optionalString($data, 'bearerFormat'),
                extensions: Value::extensions($data),
            );
        }
        if ($type === 'openIdConnect') {
            return new SecurityScheme(SecuritySchemeType::OPEN_ID_CONNECT, Value::optionalString($data, 'description') ?? '', openIdConnectUrl: Value::requiredString($data, 'openIdConnectUrl', $location), extensions: Value::extensions($data));
        }
        if ($type === 'mutualTLS' && $this->version === Version::V3_1) {
            return new SecurityScheme(SecuritySchemeType::MUTUAL_TLS, Value::optionalString($data, 'description') ?? '', extensions: Value::extensions($data));
        }
        throw new InvalidSpecification("Unsupported security scheme type '{$type}' at {$location}");
    }

    /** @return array<string, OAuthFlow> */
    private function parseOAuthFlows(mixed $raw, string $location): array
    {
        $flows = [];
        foreach (Value::object($raw, $location) as $name => $item) {
            $data = Value::object($item, "{$location}/{$name}");
            $flows[(string) $name] = new OAuthFlow(
                authorizationUrl: Value::optionalString($data, 'authorizationUrl'),
                tokenUrl: Value::optionalString($data, 'tokenUrl'),
                refreshUrl: Value::optionalString($data, 'refreshUrl'),
                scopes: $this->stringMap($data['scopes'] ?? [], "{$location}/{$name}/scopes"),
            );
        }

        return $flows;
    }

    /** @return array<string, OAuthFlow> */
    private function parseOpenApi2OAuthFlows(array $data, string $location): array
    {
        $flow = Value::requiredString($data, 'flow', $location);
        $name = match ($flow) {
            'implicit' => 'implicit',
            'password' => 'password',
            'application' => 'clientCredentials',
            'accessCode' => 'authorizationCode',
            default => throw new InvalidSpecification("Unsupported OAuth flow '{$flow}' at {$location}"),
        };

        return [$name => new OAuthFlow(
            authorizationUrl: Value::optionalString($data, 'authorizationUrl'),
            tokenUrl: Value::optionalString($data, 'tokenUrl'),
            scopes: $this->stringMap($data['scopes'] ?? [], "{$location}/scopes"),
        )];
    }

    /** @return array<string, string> */
    private function stringMap(mixed $raw, string $location): array
    {
        $result = [];
        foreach (Value::object($raw, $location) as $key => $value) {
            if (! \is_string($value)) {
                throw new InvalidSpecification("Expected string at {$location}/{$key}");
            }
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
