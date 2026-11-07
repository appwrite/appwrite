<?php

namespace Appwrite\Transformation\Adapter;

use Appwrite\Transformation\Adapter;

class Analytics extends Adapter
{
    public function __construct(
        protected string $endpoint,
        protected string $projectId,
        protected string $propertyId,
        protected string $version,
    ) {
        parent::__construct();
    }

    /**
     * @param array<mixed> $traits Proxied response headers
     */
    public function isValid(array $traits): bool
    {
        $contentType = '';
        $contentEncoding = '';

        foreach ($traits as $key => $value) {
            $key = \strtolower($key);

            if ($key === 'content-type') {
                $contentType = $value;
            } elseif ($key === 'content-encoding') {
                $contentEncoding = \strtolower(\trim($value));
            }
        }

        // Plain text appended to a compressed body would corrupt it.
        if ($contentEncoding !== '' && $contentEncoding !== 'identity') {
            return false;
        }

        return \str_contains(\strtolower($contentType), 'text/html');
    }

    public function transform(): void
    {
        $src = \htmlspecialchars("https://sdk.appwrite.io/web/{$this->version}/tracker.js");
        $endpoint = \htmlspecialchars($this->endpoint);
        $project = \htmlspecialchars($this->projectId);
        $property = \htmlspecialchars($this->propertyId);

        $this->output = $this->input . "<script defer src=\"{$src}\" data-endpoint=\"{$endpoint}\" data-project=\"{$project}\" data-property=\"{$property}\"></script>";
    }
}
