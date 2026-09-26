<?php

namespace Utopia\Http;

use Exception;

class View
{
    public const string FILTER_ESCAPE = 'escape';

    public const string FILTER_NL2P = 'nl2p';

    /**
     * @var self|null
     */
    protected ?self $parent = null;

    protected string $path = '';

    protected bool $rendered = false;

    /**
     * @var array<string, mixed>
     */
    protected array $params = [];

    /**
     * @var array<string, callable>
     */
    protected array $filters = [];

    /**
     * Constructor
     *
     * You can optionally initialize the View object with a template path, although this can also be set later using the $this->setPath($path) method
     *
     *
     * @throws Exception
     */
    public function __construct(string $path = '')
    {
        $this->setPath($path);

        $this
            ->addFilter(self::FILTER_ESCAPE, fn(string $value) => htmlentities($value, ENT_QUOTES, 'UTF-8'))
            ->addFilter(self::FILTER_NL2P, function (string $value) {
                $paragraphs = '';

                foreach (explode("\n\n", $value) as $line) {
                    if (trim($line)) {
                        $paragraphs .= '<p>' . $line . '</p>';
                    }
                }

                return str_replace("\n", '<br />', $paragraphs);
            });
    }

    /**
     * Set param
     *
     * Assign a parameter by key
     *
     *
     * @throws Exception
     */
    public function setParam(string $key, mixed $value, bool $escapeHtml = true): static
    {
        if (str_contains($key, '.')) {
            throw new Exception('$key can\'t contain a dot "." character');
        }

        if (\is_string($value) && $escapeHtml) {
            $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }

        $this->params[$key] = $value;

        return $this;
    }

    /**
     * Set parent View object conatining this object
     */
    public function setParent(self $view): static
    {
        $this->parent = $view;

        return $this;
    }

    /**
     * Return a View instance of the parent view containing this view
     */
    public function getParent(): ?self
    {
        if ($this->parent instanceof \Utopia\Http\View) {
            return $this->parent;
        }

        return null;
    }

    /**
     * Get param
     *
     * Returns an assigned parameter by its key or $default if param key doesn't exists
     *
     * @param  mixed  $default (optional)
     */
    public function getParam(string $path, mixed $default = null): mixed
    {
        $path = explode('.', $path);
        $temp = $this->params;

        foreach ($path as $key) {
            $temp = $temp[$key] ?? null;

            if (null !== $temp) {
                $value = $temp;
            } else {
                return $default;
            }
        }

        return $value;
    }

    /**
     * Set path
     *
     * Set object template path that will be used to render view output
     *
     *
     * @throws Exception
     */
    public function setPath(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Set rendered
     *
     * By enabling rendered state to true, the object will not render its template and will return an empty string instead
     */
    public function setRendered(bool $state = true): static
    {
        $this->rendered = $state;

        return $this;
    }

    /**
     * Is rendered
     *
     * Return whether current View rendering state is set to true or false
     */
    public function isRendered(): bool
    {
        return $this->rendered;
    }

    /**
     * Add Filter
     */
    public function addFilter(string $name, callable $callback): static
    {
        $this->filters[$name] = $callback;

        return $this;
    }

    /**
     * Output and filter value
     *
     * @param  string|array<int, string>  $filter
     *
     * @throws Exception
     */
    public function print(mixed $value, string|array $filter = ''): mixed
    {
        if (!empty($filter)) {
            if (\is_array($filter)) {
                foreach ($filter as $callback) {
                    if (!isset($this->filters[$callback])) {
                        throw new Exception('Filter "' . $callback . '" is not registered');
                    }

                    $value = $this->filters[$callback]($value);
                }
            } else {
                if (!isset($this->filters[$filter])) {
                    throw new Exception('Filter "' . $filter . '" is not registered');
                }

                $value = $this->filters[$filter]($value);
            }
        }

        return $value;
    }

    /**
     * Render
     *
     * Render view .phtml template file if template has not been set as rendered yet using $this->setRendered(true).
     * In case path is not readable throws Exception.
     *
     *
     * @throws Exception
     */
    public function render(bool $minify = true): string
    {
        if ($this->rendered) { // Don't render any template
            return '';
        }

        ob_start(); //Start of build

        if (is_readable($this->path)) {
            /**
             * Include template file
             *
             * @psalm-suppress UnresolvableInclude
             */
            include $this->path;
        } else {
            ob_end_clean();
            throw new Exception('"' . $this->path . '" view template is not readable');
        }

        $html = ob_get_contents() ?: '';

        ob_end_clean(); //End of build

        if ($minify) {
            // Searching textarea and pre
            preg_match_all('#\<textarea.*\>.*\<\/textarea\>#Uis', $html, $foundTxt);
            preg_match_all('#\<pre.*\>.*\<\/pre\>#Uis', $html, $foundPre);

            // replacing both with <textarea>$index</textarea> / <pre>$index</pre>
            $html = str_replace($foundTxt[0], array_map(fn($el) => '<textarea>' . $el . '</textarea>', array_keys($foundTxt[0])), $html);
            $html = str_replace($foundPre[0], array_map(fn($el) => '<pre>' . $el . '</pre>', array_keys($foundPre[0])), $html);

            // your stuff
            $search = [
                '/\>[^\S ]+/s',  // strip whitespaces after tags, except space
                '/[^\S ]+\</s',  // strip whitespaces before tags, except space
                '/(\s)+/s',       // shorten multiple whitespace sequences
            ];

            $replace = [
                '>',
                '<',
                '\\1',
            ];

            $html = preg_replace($search, $replace, $html) ?? $html;

            // Replacing back with content
            $html = str_replace(array_map(fn($el) => '<textarea>' . $el . '</textarea>', array_keys($foundTxt[0])), $foundTxt[0], $html);
            $html = str_replace(array_map(fn($el) => '<pre>' . $el . '</pre>', array_keys($foundPre[0])), $foundPre[0], $html);
        }

        return $html;
    }

    /* View Helpers */
    /**
     * Exec
     *
     * Exec child View components
     *
     * @param  array<int, mixed>|self  $view
     *
     * @throws Exception
     */
    public function exec($view): string
    {
        $output = '';

        if (\is_array($view)) {
            foreach ($view as $node) { /* @var $node self */
                if ($node instanceof self) {
                    $node->setParent($this);
                    $output .= $node->render();
                }
            }
        }

        if ($view instanceof self) {
            $view->setParent($this);
            $output = $view->render();
        }

        return $output;
    }
}
