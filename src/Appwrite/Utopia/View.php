<?php

namespace Appwrite\Utopia;

use Exception;

class View
{
    public const FILTER_ESCAPE = 'escape';

    public const FILTER_NL2P = 'nl2p';

    /**
     * @var self|null
     */
    protected ?self $parent = null;

    /**
     * @var string
     */
    protected string $path = '';

    /**
     * @var bool
     */
    protected bool $rendered = false;

    /**
     * @var array
     */
    protected array $params = [];

    /**
     * @var array
     */
    protected array $filters = [];

    /**
     * Constructor
     *
     * You can optionally initialize the View object with a template path, although this can also be set later using the $this->setPath($path) method
     *
     * @param  string  $path
     *
     * @throws Exception
     */
    public function __construct(string $path = '')
    {
        $this->setPath($path);

        $this
            ->addFilter(self::FILTER_ESCAPE, function (string $value) {
                return \htmlentities($value, ENT_QUOTES, 'UTF-8');
            })
            ->addFilter(self::FILTER_NL2P, function (string $value) {
                $paragraphs = '';

                foreach (\explode("\n\n", $value) as $line) {
                    if (\trim($line)) {
                        $paragraphs .= '<p>'.$line.'</p>';
                    }
                }

                $paragraphs = \str_replace("\n", '<br />', $paragraphs);

                return $paragraphs;
            });
    }

    /**
     * Set param
     *
     * Assign a parameter by key
     *
     * @param  string  $key
     * @param  mixed  $value
     *
     * @throws Exception
     */
    public function setParam(string $key, mixed $value): static
    {
        if (\strpos($key, '.') !== false) {
            throw new Exception('$key can\'t contain a dot "." character');
        }

        $this->params[$key] = $value;

        return $this;
    }

    /**
     * Set parent View object conatining this object
     *
     * @param  self  $view
     */
    public function setParent(self $view): static
    {
        $this->parent = $view;

        return $this;
    }

    /**
     * Return a View instance of the parent view containing this view
     *
     * @return self|null
     */
    public function getParent(): ?self
    {
        if (! empty($this->parent)) {
            return $this->parent;
        }

        return null;
    }

    /**
     * Get param
     *
     * Returns an assigned parameter by its key or $default if param key doesn't exists
     *
     * @param  string  $path
     * @param  mixed  $default (optional)
     * @return mixed
     */
    public function getParam(string $path, mixed $default = null): mixed
    {
        $path = \explode('.', $path);
        $temp = $this->params;

        foreach ($path as $key) {
            $temp = (isset($temp[$key])) ? $temp[$key] : null;

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
     * @param  string  $path
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
     *
     * @param  bool  $state
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
     *
     * @return bool
     */
    public function isRendered(): bool
    {
        return $this->rendered;
    }

    /**
     * Add Filter
     *
     * @param  string  $name
     * @param  callable  $callback
     */
    public function addFilter(string $name, callable $callback): static
    {
        $this->filters[$name] = $callback;

        return $this;
    }

    /**
     * Output and filter value
     *
     * @param  mixed  $value
     * @param  string|array  $filter
     * @return mixed
     *
     * @throws Exception
     */
    public function print(mixed $value, string|array $filter = ''): mixed
    {
        if (! empty($filter)) {
            if (\is_array($filter)) {
                foreach ($filter as $callback) {
                    if (! isset($this->filters[$callback])) {
                        throw new Exception('Filter "'.$callback.'" is not registered');
                    }

                    $value = $this->filters[$callback]($value);
                }
            } else {
                if (! isset($this->filters[$filter])) {
                    throw new Exception('Filter "'.$filter.'" is not registered');
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
     * @param  bool  $minify
     * @return string
     *
     * @throws Exception
     */
    public function render(bool $minify = true): string
    {
        if ($this->rendered) { // Don't render any template
            return '';
        }

        \ob_start(); //Start of build

        if (\is_readable($this->path)) {
            /**
             * Include template file
             *
             * @psalm-suppress UnresolvableInclude
             */
            include $this->path;
        } else {
            \ob_end_clean();
            throw new Exception('"'.$this->path.'" view template is not readable');
        }

        $html = \ob_get_contents();

        \ob_end_clean(); //End of build

        if ($minify) {
            // Searching textarea and pre
            \preg_match_all('#\<textarea.*\>.*\<\/textarea\>#Uis', $html, $foundTxt);
            \preg_match_all('#\<pre.*\>.*\<\/pre\>#Uis', $html, $foundPre);

            // replacing both with <textarea>$index</textarea> / <pre>$index</pre>
            $html = \str_replace($foundTxt[0], \array_map(function ($el) {
                return '<textarea>'.$el.'</textarea>';
            }, \array_keys($foundTxt[0])), $html);
            $html = \str_replace($foundPre[0], \array_map(function ($el) {
                return '<pre>'.$el.'</pre>';
            }, \array_keys($foundPre[0])), $html);

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

            $html = \preg_replace($search, $replace, $html);

            // Replacing back with content
            $html = \str_replace(\array_map(function ($el) {
                return '<textarea>'.$el.'</textarea>';
            }, \array_keys($foundTxt[0])), $foundTxt[0], $html);
            $html = \str_replace(\array_map(function ($el) {
                return '<pre>'.$el.'</pre>';
            }, \array_keys($foundPre[0])), $foundPre[0], $html);
        }

        return $html;
    }

    /* View Helpers */

    /**
     * Exec
     *
     * Exec child View components
     *
     * @param  array|self  $view
     * @return string
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
    
    /**
     * Escape
     *
     * Convert all applicable characters to HTML entities
     *
     * @param  string $str
     * @return string
     * @deprecated Use print method with escape filter
     */
    public function escape($str)
    {
        return \htmlentities($str, ENT_QUOTES, 'UTF-8');
    }
}