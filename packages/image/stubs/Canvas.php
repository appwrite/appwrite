<?php

// Utopia\Image\Canvas and Utopia\Image\Font are registered by the utopia PHP extension
// (crates/extension in appwrite/appwrite). This file only declares them for static analysis
// and editors; it is never loaded.

namespace Utopia\Image;

use Utopia\Image\Exception\CorruptImageException;
use Utopia\Image\Exception\ImageException;
use Utopia\Image\Exception\LimitExceededException;
use Utopia\Image\Exception\UnsupportedFormatException;

/**
 * An image and the work planned on it. A canvas is immutable: apply() and draw() return a new
 * one. Nothing is decoded until encode(), which decodes, runs the plan and writes the result
 * in one pass; the size, format and orientation come from the header.
 */
final readonly class Canvas
{
    /** The width of the source, before any step, in pixels. */
    public int $width;

    /** The height of the source, before any step, in pixels. */
    public int $height;

    /** The EXIF orientation of the source, 1 to 8 (1 when it has none). */
    public int $orientation;

    /** The format of the source; null for a blank canvas. */
    public ?Format $format;

    /**
     * Reads the header of an encoded image, or makes a canvas of one colour.
     *
     * @throws UnsupportedFormatException When the format is unknown, or not one the limits accept.
     * @throws CorruptImageException When the header cannot be read.
     * @throws LimitExceededException When the size is past the limits.
     * @throws \InvalidArgumentException
     */
    public function __construct(string|Blank $source, ?Limits $limits = null)
    {
    }

    /**
     * @throws \InvalidArgumentException When a colour cannot be parsed.
     */
    public function apply(Transform $transform): static
    {
    }

    /**
     * @throws \InvalidArgumentException When a colour or a path cannot be parsed.
     */
    public function draw(Path|Text $drawing): static
    {
    }

    /**
     * Decodes the source, runs the plan and writes the format. Metadata is not written.
     *
     * @param int|null $quality 0 to 100; null keeps a JPEG source's own quality and otherwise uses the format's default.
     *
     * @throws UnsupportedFormatException When this build has no encoder for the format.
     * @throws CorruptImageException When the pixel data is damaged.
     * @throws LimitExceededException
     * @throws ImageException
     * @throws \InvalidArgumentException
     */
    public function encode(Format $format, ?int $quality = null): string
    {
    }
}

/**
 * A TrueType or OpenType font file, read once.
 */
final readonly class Font
{
    /**
     * @throws \InvalidArgumentException When the file cannot be read or is not a font.
     */
    public function __construct(string $path)
    {
    }

    /**
     * Whether the font draws something for the character.
     *
     * @throws \InvalidArgumentException When $character is not exactly one character.
     */
    public function has(string $character): bool
    {
    }
}
