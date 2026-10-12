<?php

namespace Tests\Compat\Psr7;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request;
use Utopia\Psr7\Request\Multipart\Body;
use Utopia\Psr7\Request\Multipart\Part;
use Utopia\Psr7\Response;
use Utopia\Psr7\Response\Multipart\Part as ReceivedPart;
use Utopia\Psr7\ServerRequest;
use Utopia\Psr7\Stream;
use Utopia\Psr7\UploadedFile;
use Utopia\Psr7\Uri;

/**
 * Maps tests/compat/psr7/spec.json operations onto utopia-php/psr7. Glue only: no logic.
 *
 * Objects (URIs, streams, messages, parts, uploaded files) live behind
 * handles; `*.with` and `*.call` operations call one method by name, and
 * `*.dump` operations read every getter that has no side effect.
 */
final class Adapter implements Base, Benchmarks
{
    private const string BODY = '{"id":"6f1c","name":"Ada Lovelace","email":"ada@example.com","roles":["admin","owner"],"active":true,"logins":42}';

    public function benchmarks(): array
    {
        $multipart = "--abc123\r\nContent-Disposition: form-data; name=\"name\"\r\n\r\nAda\r\n"
            . "--abc123\r\nContent-Disposition: form-data; name=\"avatar\"; filename=\"ada.png\"\r\nContent-Type: image/png\r\n\r\nimage-bytes\r\n--abc123--\r\n";

        return [
            'uri_parse' => ['Parse a URI with credentials, a port, a path, a query and a fragment', fn (Session $s) => fn () => (string) Uri::parse('https://user:pass@api.example.com:8443/v1/users/6f1c?limit=25&cursor=abc#top')],
            'create_request' => ['Create a request from a method and URI string (Host set from the URI)', fn (Session $s) => fn () => (new Request\Factory())->createRequest('get', 'https://api.example.com/v1/users?limit=25')],
            'headers' => ['Set three headers on a response and read one back case-insensitively', fn (Session $s) => fn () => (new Response())
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-Request-Id', 'abc')
                ->withAddedHeader('Cache-Control', ['no-cache', 'no-store'])
                ->getHeaderLine('cache-control')],
            'json_request' => ['Build a JSON request with an Authorization header', fn (Session $s) => fn () => (new Request\Factory())->json('POST', 'https://api.example.com/v1/users', ['name' => 'Ada', 'roles' => ['admin']], ['Authorization' => 'Bearer token'])],
            'json_response' => ['Decode a JSON response body', fn (Session $s) => function () {
                return (new Response())->withBody(new Stream(self::BODY))->json();
            }],
            'multipart_response' => ['Split a two-part multipart response and read each part\'s name', fn (Session $s) => function () use ($multipart) {
                $parts = (new Response())->withHeader('Content-Type', 'multipart/form-data; boundary=abc123')->withBody(new Stream($multipart))->multipart();

                return [$parts[0]->name(), $parts[1]->filename()];
            }],
        ];
    }

    public function operations(): array
    {
        return [
            'constants' => fn (array $a, Session $s) => [
                'method' => (new \ReflectionClass(Method::class))->getConstants(),
                'header' => (new \ReflectionClass(Header::class))->getConstants(),
                'contentType' => (new \ReflectionClass(ContentType::class))->getConstants(),
            ],

            // Test setup, as the PHP tests call file_put_contents(): no library call.
            'fixture.write' => function (array $a, Session $s) {
                if (!is_dir(\dirname($a['path']))) {
                    mkdir(\dirname($a['path']), 0777, true);
                }
                file_put_contents($a['path'], $a['content'] ?? '');

                return;
            },

            // One-shot forms for fuzzing.
            'uri.inspect' => fn (array $a, Session $s) => self::dumpUri(Uri::parse($a['uri'])),
            'uri.change' => fn (array $a, Session $s) => self::dumpUri(Uri::parse($a['uri'])->{$a['method']}(...($a['args'] ?? []))),
            'header.set' => fn (array $a, Session $s) => (new Response())->withHeader($a['name'], $a['value'])->getHeaders(),
            'response.read' => function (array $a, Session $s) {
                $response = (new Response())->withBody(new Stream($a['body'] ?? ''));
                if (isset($a['contentType'])) {
                    $response = $response->withHeader('Content-Type', $a['contentType']);
                }

                return self::responseCall($response, $a['method'], $a['args'] ?? [], $a['lookup'] ?? []);
            },

            'uri.parse' => fn (array $a, Session $s) => $s->handle(Uri::parse($a['uri'])),
            'uri.factory' => fn (array $a, Session $s) => $s->handle((new Uri\Factory())->createUri($a['uri'] ?? '')),
            'uri.new' => fn (array $a, Session $s) => $s->handle(new Uri(
                $a['scheme'] ?? '',
                $a['userInfo'] ?? '',
                $a['host'] ?? '',
                $a['port'] ?? null,
                $a['path'] ?? '',
                $a['query'] ?? '',
                $a['fragment'] ?? '',
            )),
            'uri.with' => fn (array $a, Session $s) => $s->handle(self::uri($a['uri'], $s)->{$a['method']}(...self::args($a['args'] ?? [], $s))),
            'uri.dump' => fn (array $a, Session $s) => self::dumpUri(self::uri($a['uri'], $s)),

            'stream.new' => fn (array $a, Session $s) => $s->handle(new Stream($a['content'] ?? '')),
            'stream.factory' => fn (array $a, Session $s) => $s->handle((new Stream\Factory())->createStream($a['content'] ?? '')),
            'stream.from_file' => fn (array $a, Session $s) => $s->handle(isset($a['mode'])
                ? (new Stream\Factory())->createStreamFromFile($a['path'], $a['mode'])
                : (new Stream\Factory())->createStreamFromFile($a['path'])),
            'stream.from_resource' => function (array $a, Session $s) {
                $resource = fopen('php://temp', 'r+');
                fwrite($resource, $a['content'] ?? '');

                return $s->handle(($a['factory'] ?? true) ? (new Stream\Factory())->createStreamFromResource($resource) : Stream::fromResource($resource));
            },
            'stream.from_detached' => fn (array $a, Session $s) => $s->handle(Stream::fromResource(self::stream($a['stream'], $s)->detach())),
            'stream.call' => fn (array $a, Session $s) => self::streamCall(self::stream($a['stream'], $s), $a['method'], $a['args'] ?? []),

            'request.new' => fn (array $a, Session $s) => $s->handle(new Request($a['method'], self::uri($a['uri'], $s), isset($a['body']) ? self::stream($a['body'], $s) : null)),
            'request.factory' => fn (array $a, Session $s) => $s->handle((new Request\Factory())->{$a['method']}(...self::args($a['args'] ?? [], $s))),
            'response.new' => fn (array $a, Session $s) => $s->handle(new Response($a['status'] ?? 200, $a['reason'] ?? '', isset($a['body']) ? self::stream($a['body'], $s) : null)),
            'response.factory' => fn (array $a, Session $s) => $s->handle((new Response\Factory())->createResponse(...self::args($a['args'] ?? [], $s))),
            'server_request.new' => fn (array $a, Session $s) => $s->handle(new ServerRequest(
                $a['method'],
                self::uri($a['uri'], $s),
                $a['serverParams'] ?? [],
                $a['cookieParams'] ?? [],
                $a['queryParams'] ?? [],
                self::files($a['uploadedFiles'] ?? [], $s),
                $a['parsedBody'] ?? null,
                $a['attributes'] ?? [],
                $a['requestTarget'] ?? null,
                isset($a['body']) ? self::stream($a['body'], $s) : null,
                $a['headers'] ?? [],
            )),
            'server_request.factory' => fn (array $a, Session $s) => $s->handle((new ServerRequest\Factory())->createServerRequest(
                $a['method'],
                \is_string($a['uri']) ? $a['uri'] : self::uri($a['uri'], $s),
                $a['serverParams'] ?? [],
            )),
            'message.with' => fn (array $a, Session $s) => $s->handle(self::message($a['message'], $s)->{$a['method']}(...self::args($a['args'] ?? [], $s))),
            'message.get' => function (array $a, Session $s) {
                $result = self::message($a['message'], $s)->{$a['method']}(...self::args($a['args'] ?? [], $s));

                return $a['method'] === 'getUploadedFiles' ? self::dumpFiles($result) : $result;
            },
            'message.body' => fn (array $a, Session $s) => $s->handle(self::message($a['message'], $s)->getBody()),
            'message.uri' => fn (array $a, Session $s) => $s->handle(self::request($a['message'], $s)->getUri()),
            'message.dump' => fn (array $a, Session $s) => self::dumpMessage(self::message($a['message'], $s)),
            'response.call' => fn (array $a, Session $s) => self::responseCall(self::message($a['message'], $s), $a['method'], $a['args'] ?? [], $a['lookup'] ?? []),

            'part.field' => fn (array $a, Session $s) => $s->handle(Part::field($a['name'], $a['value'], $a['headers'] ?? [])),
            'part.file' => fn (array $a, Session $s) => $s->handle(Part::file($a['name'], $a['path'], $a['filename'] ?? null, $a['contentType'] ?? null, $a['headers'] ?? [])),
            'part.body' => fn (array $a, Session $s) => $s->handle(Part::body($a['name'], $a['body'], $a['filename'] ?? null, $a['contentType'] ?? null, $a['headers'] ?? [])),
            'part.dump' => fn (array $a, Session $s) => self::dumpPart(self::part($a['part'], $s)),
            'multipart.body' => fn (array $a, Session $s) => $s->handle(new Body($a['boundary'], array_map(fn ($p) => self::part($p, $s), $a['parts'] ?? []))),
            'multipart.dump' => function (array $a, Session $s) {
                $body = $s->get($a['body']);
                if (!$body instanceof Body) {
                    throw new Fault('not a multipart body');
                }

                return ['boundary' => $body->boundary(), 'parts' => array_map(self::dumpPart(...), $body->parts())];
            },
            // The random boundary of a multipart request reads as {boundary}.
            'multipart.render' => function (array $a, Session $s) {
                $request = self::message($a['message'], $s);
                $body = $request->getBody();
                if (!$body instanceof Body) {
                    throw new Fault('not a multipart body');
                }
                $boundary = $body->boundary();

                return [
                    'contentType' => str_replace($boundary, '{boundary}', $request->getHeaderLine('Content-Type')),
                    'body' => str_replace($boundary, '{boundary}', (string) $body),
                    'prefix' => substr($boundary, 0, 7),
                    'length' => \strlen($boundary),
                    'hex' => ctype_xdigit(substr($boundary, 7)) && strtolower(substr($boundary, 7)) === substr($boundary, 7),
                ];
            },
            'received.new' => fn (array $a, Session $s) => self::dumpReceived(new ReceivedPart($a['headers'] ?? [], $a['body'] ?? ''), $a['lookup'] ?? []),

            'uploaded.new' => fn (array $a, Session $s) => $s->handle(new UploadedFile($a['file'], $a['size'], $a['error'], $a['clientFilename'] ?? null, $a['clientMediaType'] ?? null)),
            'uploaded.call' => function (array $a, Session $s) {
                $file = $s->get($a['file']);
                if (!$file instanceof UploadedFile) {
                    throw new Fault('not an uploaded file');
                }
                $result = $file->{$a['method']}(...self::args($a['args'] ?? [], $s));

                return $result instanceof StreamInterface ? $s->handle($result) : $result;
            },
            'uploaded.normalize' => fn (array $a, Session $s) => self::dumpFiles(UploadedFile::normalizeFiles($a['files'] ?? [])),
        ];
    }

    /**
     * Arguments by position: handles become their objects, `{"$null": true}` is null.
     *
     * @param list<mixed> $args
     * @return list<mixed>
     */
    private static function args(array $args, Session $s): array
    {
        return array_map(fn ($arg) => self::arg($arg, $s), $args);
    }

    private static function arg(mixed $arg, Session $s): mixed
    {
        if (\is_array($arg) && isset($arg['$handle'])) {
            return $s->get($arg);
        }
        if (\is_array($arg) && isset($arg['$files'])) {
            return self::files($arg['$files'], $s);
        }
        if (\is_array($arg) && isset($arg['$parts'])) {
            return array_map(fn ($p) => \is_array($p) && isset($p['$handle']) ? $s->get($p) : $p, $arg['$parts']);
        }

        return $arg;
    }

    /**
     * An uploaded files tree: handles become their UploadedFile objects.
     */
    private static function files(array $tree, Session $s): array
    {
        return array_map(fn ($node) => \is_array($node) && isset($node['$handle']) ? $s->get($node) : (\is_array($node) ? self::files($node, $s) : $node), $tree);
    }

    private static function uri(mixed $handle, Session $s): UriInterface
    {
        $uri = $s->get($handle);

        return $uri instanceof UriInterface ? $uri : throw new Fault('not a URI');
    }

    private static function stream(mixed $handle, Session $s): StreamInterface
    {
        $stream = $s->get($handle);

        return $stream instanceof StreamInterface ? $stream : throw new Fault('not a stream');
    }

    private static function message(mixed $handle, Session $s): MessageInterface
    {
        $message = $s->get($handle);

        return $message instanceof MessageInterface ? $message : throw new Fault('not a message');
    }

    private static function request(mixed $handle, Session $s): RequestInterface
    {
        $request = self::message($handle, $s);

        return $request instanceof RequestInterface ? $request : throw new Fault('not a request');
    }

    private static function part(mixed $handle, Session $s): Part
    {
        $part = $s->get($handle);

        return $part instanceof Part ? $part : throw new Fault('not a part');
    }

    private static function dumpUri(UriInterface $uri): array
    {
        return [
            'scheme' => $uri->getScheme(),
            'authority' => $uri->getAuthority(),
            'userInfo' => $uri->getUserInfo(),
            'host' => $uri->getHost(),
            'port' => $uri->getPort(),
            'path' => $uri->getPath(),
            'query' => $uri->getQuery(),
            'fragment' => $uri->getFragment(),
            'string' => (string) $uri,
        ];
    }

    /**
     * One stream method; `detach` reports what the resource still holds.
     */
    private static function streamCall(StreamInterface $stream, string $method, array $args): mixed
    {
        if ($method === 'toString') {
            return (string) $stream;
        }
        $result = $stream->{$method}(...$args);
        if ($method === 'detach') {
            return \is_resource($result) ? ['contents' => stream_get_contents($result)] : $result;
        }

        return $result;
    }

    private static function dumpMessage(MessageInterface $message): array
    {
        $dump = [
            'protocol' => $message->getProtocolVersion(),
            'headers' => $message->getHeaders(),
        ];
        if ($message instanceof Request || $message instanceof ServerRequest) {
            $dump['method'] = $message->getMethod();
            $dump['target'] = $message->getRequestTarget();
            $dump['uri'] = (string) $message->getUri();
        }
        if ($message instanceof Response) {
            $dump['status'] = $message->getStatusCode();
            $dump['reason'] = $message->getReasonPhrase();
        }
        if ($message instanceof ServerRequest) {
            $dump['serverParams'] = $message->getServerParams();
            $dump['cookieParams'] = $message->getCookieParams();
            $dump['queryParams'] = $message->getQueryParams();
            $dump['uploadedFiles'] = self::dumpFiles($message->getUploadedFiles());
            $dump['parsedBody'] = $message->getParsedBody();
            $dump['attributes'] = $message->getAttributes();
        }

        return $dump;
    }

    private static function dumpFiles(array $tree): array
    {
        return array_map(fn ($node) => $node instanceof UploadedFile ? [
            'size' => $node->getSize(),
            'error' => $node->getError(),
            'clientFilename' => $node->getClientFilename(),
            'clientMediaType' => $node->getClientMediaType(),
        ] : self::dumpFiles($node), $tree);
    }

    private static function responseCall(MessageInterface $response, string $method, array $args, array $lookup): mixed
    {
        if (!$response instanceof Response) {
            throw new Fault('not a response');
        }
        $result = $response->{$method}(...$args);

        return match ($method) {
            'xml' => self::dumpXml($result),
            'multipart' => array_map(fn (ReceivedPart $p) => self::dumpReceived($p, $lookup), $result),
            default => $result,
        };
    }

    private static function dumpXml(\SimpleXMLElement $element): array
    {
        $attributes = [];
        foreach ($element->attributes() as $name => $value) {
            $attributes[] = [$name, (string) $value];
        }
        $children = [];
        foreach ($element->children() as $child) {
            $children[] = self::dumpXml($child);
        }

        return ['name' => $element->getName(), 'attributes' => $attributes, 'text' => (string) $element, 'children' => $children];
    }

    /**
     * @param list<string> $lookup header names to read with header() and headerLine()
     */
    private static function dumpReceived(ReceivedPart $part, array $lookup): array
    {
        return [
            'headers' => $part->headers(),
            'body' => $part->body(),
            'name' => $part->name(),
            'filename' => $part->filename(),
            'contentType' => $part->contentType(),
            'lookup' => array_map(fn (string $name) => [$part->header($name), $part->headerLine($name)], $lookup),
        ];
    }

    private static function dumpPart(Part $part): array
    {
        return [
            'name' => $part->name(),
            'content' => $part->content(),
            'path' => $part->path(),
            'size' => $part->size(),
            'filename' => $part->filename(),
            'contentType' => $part->contentType(),
            'headers' => $part->headers(),
        ];
    }
}
