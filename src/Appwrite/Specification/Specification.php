<?php

namespace Appwrite\Specification;

class Specification
{
    /**
     * @var Format
     */
    protected $format;

    /**
     * @param Format $format
     */
    public function __construct(Format $format)
    {
        $this->format = $format;
    }

    /**
     * Get Name.
     *
     * Get format name
     *
     * @return string
     */
    public function getName():string
    {
        return $this->format->getName();
    }

    /**
     * Parse
     *
     * Parses Appwrite App to given format
     *
     * @return array
     */
    public function parse(): array
    {
        return $this->format->parse();
    }
}