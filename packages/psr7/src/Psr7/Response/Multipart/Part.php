<?php

declare(strict_types=1);

namespace Utopia\Psr7\Response\Multipart;

final readonly class Part
{
    /**
     * @param array<string, array<int, string>> $headers
     */
    public function __construct(
        private array $headers,
        private string $body,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @return array<int, string>
     */
    public function header(string $name): array
    {
        foreach ($this->headers as $header => $values) {
            if (strcasecmp($header, $name) === 0) {
                return $values;
            }
        }

        return [];
    }

    public function headerLine(string $name): string
    {
        return implode(', ', $this->header($name));
    }

    public function body(): string
    {
        return $this->body;
    }

    public function name(): ?string
    {
        return $this->contentDispositionParameter('name');
    }

    public function filename(): ?string
    {
        return $this->contentDispositionParameter('filename');
    }

    public function contentType(): ?string
    {
        $contentType = $this->headerLine('Content-Type');

        return $contentType === '' ? null : $contentType;
    }

    private function contentDispositionParameter(string $parameter): ?string
    {
        $contentDisposition = $this->headerLine('Content-Disposition');

        if ($contentDisposition === '') {
            return null;
        }

        if (preg_match('/(?:^|;\s*)' . preg_quote($parameter, '/') . '=(?:"(?P<quoted>(?:[^"\\\\]|\\\\.)*)"|(?P<token>[^;\s]+))/', $contentDisposition, $matches, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }

        if ($matches['quoted'] !== null) {
            return stripcslashes($matches['quoted']);
        }

        return $matches['token'] ?? null;
    }
}
