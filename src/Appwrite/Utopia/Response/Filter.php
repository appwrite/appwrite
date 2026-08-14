<?php

namespace Appwrite\Utopia\Response;

use Utopia\Database\Document;

abstract class Filter
{
    /**
     * @var ?Document $rawContent
     */
    protected ?Document $rawContent = null;

    /**
     * Parse the content to another format.
     *
     * @param array $content
     * @param string $model
     *
     * @return array
     */
    abstract public function parse(array $content, string $model): array;

    public function setRawContent(Document $rawContent): void
    {
        $this->rawContent = $rawContent;
    }

    /**
     * Handle list
     *
     * @param array $content
     * @param string $key
     * @param callable $callback
     *
     * @return array
     */
    protected function handleList(array $content, string $key, callable $callback): array
    {
        if (array_key_exists($key, $content) && \is_array($content[$key])) {
            foreach ($content[$key] as $i => $item) {
                $content[$key][$i] = $callback($item);
            }
        }

        return $content;
    }
}
