<?php

namespace Template;

use Exception;
use Utopia\View;

class Template extends View
{
    /**
     * Render.
     *
     * Render view .phtml template file if template has not been set as rendered yet using $this->setRendered(true).
     * In case path is not readable throws Exception.
     *
     * @return string
     *
     * @throws Exception
     */
    public function render($minify = true)
    {
        if ($this->rendered) { // Don't render any template
            return '';
        }

        if (is_readable($this->path)) {
            $template = file_get_contents($this->path); // Include template file
        } else {
            throw new Exception('"'.$this->path.'" template is not readable or not found');
        }

        $template = str_replace(array_keys($this->params), array_values($this->params), $template);

        return $template;
    }

    /**
     * Parse URL.
     *
     * Parse URL string to array
     *
     * @param $url
     *
     * @return mixed On seriously malformed URLs, parse_url() may return FALSE.
     */
    public static function parseURL($url)
    {
        return parse_url($url);
    }

    /**
     * Un-Parse URL.
     *
     * Convert PHP array to query string
     *
     * @param $url
     *
     * @return string
     */
    public static function unParseURL(array $url)
    {
        $scheme = isset($url['scheme']) ? $url['scheme'].'://' : '';
        $host = isset($url['host']) ? $url['host'] : '';
        $port = isset($url['port']) ? ':'.$url['port'] : '';

        $user = isset($url['user']) ? $url['user'] : '';
        $pass = isset($url['pass']) ? ':'.$url['pass']  : '';
        $pass = ($user || $pass) ? "$pass@" : '';

        $path = isset($url['path']) ? $url['path'] : '';
        $query = isset($url['query']) && !empty($url['query']) ? '?'.$url['query'] : '';

        $fragment = isset($url['fragment']) ? '#'.$url['fragment'] : '';

        return $scheme.$user.$pass.$host.$port.$path.$query.$fragment;
    }

    /**
     * Merge Query.
     *
     * Merge array of params to query string
     *
     * @param $query1
     * @param array $query2
     *
     * @return string
     */
    public static function mergeQuery($query1, array $query2)
    {
        $parsed = [];

        parse_str($query1, $parsed);

        $parsed = array_merge($parsed, $query2);

        return http_build_query($parsed);
    }
}
