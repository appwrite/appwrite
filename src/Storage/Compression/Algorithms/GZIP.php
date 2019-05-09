<?php

namespace Storage\Compression\Algorithms;

use Storage\Compression\Compression;

class GZIP extends Compression
{
    /**
     * @return string
     */
    public function getName(): string
    {
        return 'gzip';
    }

    /**
     * Compress
     *
     * We use gzencode over gzcompress for better support of the first format among other tools.
     * (http://stackoverflow.com/a/621987/2299554)
     *
     * @see http://php.net/manual/en/function.gzencode.php
     *
     * @param string $data
     * @return string
     */
    public function compress(string $data):string
    {
        return gzencode($data);
    }

    /**
     * Decompress
     *
     * @param string $data
     * @return string
     */
    public function decompress(string $data):string
    {
        return gzdecode($data);
    }
}