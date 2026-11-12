<?php

namespace Utopia\Image;

use Imagick;
use ImagickDraw;
use ImagickPixel;
use Utopia\Image\Exception\CorruptImageException;
use Utopia\Image\Exception\ImageException;
use Utopia\Image\Exception\LimitExceededException;
use Utopia\Image\Exception\UnsupportedFormatException;

class Image
{
    public const GRAVITY_CENTER = 'center';

    public const GRAVITY_TOP_LEFT = 'top-left';

    public const GRAVITY_TOP = 'top';

    public const GRAVITY_TOP_RIGHT = 'top-right';

    public const GRAVITY_LEFT = 'left';

    public const GRAVITY_RIGHT = 'right';

    public const GRAVITY_BOTTOM_LEFT = 'bottom-left';

    public const GRAVITY_BOTTOM = 'bottom';

    public const GRAVITY_BOTTOM_RIGHT = 'bottom-right';

    private Imagick $image;

    private int $width;

    private int $height;

    private int $cornerRadius = 0;

    private int $borderWidth = 0;

    private string $borderColor = '';

    private int $rotation = 0;

    /**
     * @throws UnsupportedFormatException
     * @throws CorruptImageException
     * @throws LimitExceededException
     * @throws ImageException
     */
    public function __construct(string $data)
    {
        $this->image = new Imagick();

        $format = Format::detect($data);
        try {
            $this->image->setFormat($format->value);
            $this->image->readImageBlob($data);
            $this->image->setFormat('');
        } catch (\ImagickException $exception) {
            // CoderError: while reading, a decoder refusing the data (a bad PNG chunk or zlib stream).
            if ($exception->getCode() === 450) {
                throw new CorruptImageException($exception);
            }
            throw self::failure($exception, 'Failed to process image', $format->value . ' input is not available');
        }

        // Solve formats such as GIF. Otherwise width&height would be from last frame (wrong)
        $this->image->setFirstIterator();

        $this->width = $this->image->getImageWidth();
        $this->height = $this->image->getImageHeight();

        // Use metadata to fetch rotation. Will be perform right before exporting
        $orientationType = $this->image->getImageProperties()['exif:Orientation'] ?? null;

        // The rotation is applied to the pixels, so the image must stop saying it still needs one:
        // left in place, the tag is written to JPEG, WebP and PNG output on the crop paths that
        // keep metadata, and as a rotation of its own to AVIF and HEIC, and a viewer turns the
        // image a second time.
        $this->image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
        if (! empty($orientationType)) {
            // PNG writes the tag from the EXIF data itself.
            $this->image->setImageProperty('exif:Orientation', '1');
        }

        // Reference: https://docs.imgix.com/apis/rendering/rotation/orient
        // Mirror rotations are ignored, because we don't support mirroring
        if (! empty($orientationType)) {
            switch ($orientationType) {
                case '3':
                    $this->rotation = 180;
                    break;

                case '6':
                    $this->rotation = 90;
                    break;

                case '8':
                    $this->rotation = -90;
                    break;
            }
        }
    }

    /**
     * @return array<string>
     */
    public static function getGravityTypes(): array
    {
        return [
            Image::GRAVITY_CENTER,
            Image::GRAVITY_TOP_LEFT,
            Image::GRAVITY_TOP,
            Image::GRAVITY_TOP_RIGHT,
            Image::GRAVITY_LEFT,
            Image::GRAVITY_RIGHT,
            Image::GRAVITY_BOTTOM_LEFT,
            Image::GRAVITY_BOTTOM,
            Image::GRAVITY_BOTTOM_RIGHT,
        ];
    }

    /**
     * @throws \InvalidArgumentException
     * @throws LimitExceededException
     * @throws ImageException
     */
    public function crop(
        int $width,
        int $height,
        string $gravity = Image::GRAVITY_CENTER,
        ?float $x = null,
        ?float $y = null,
    ): self {
        $this->validateFocalPoint($x, $y);

        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('Width and height must not be negative');
        }

        $hasFocalPoint = $x !== null && $y !== null;

        // if no changes to Gravity, Width or Height, don't process image
        if ($gravity === Image::GRAVITY_CENTER && !$hasFocalPoint
            && (
                ($width !== 0 && $height !== 0)
                && ($width === $this->width && $height === $this->height)
            )) {
            return $this;
        }

        $originalAspect = $this->width / $this->height;

        // A derived side rounds down, so a 1px side on a skewed source must not collapse to 0.
        if ($width === 0 && $height === 0) {
            $height = $this->height;
            $width = $this->width;
        } elseif ($width === 0) {
            $width = max(1, \intval($height * $originalAspect));
        } elseif ($height === 0) {
            $height = max(1, \intval($width / $originalAspect));
        }

        $resizeWidth = $this->width;
        $resizeHeight = $this->height;
        if ($gravity !== Image::GRAVITY_CENTER || $hasFocalPoint) {
            $targetAspect = $width / $height;
            if ($targetAspect > $originalAspect) {
                $resizeWidth = $width;
                $resizeHeight = \intval(ceil($width / $originalAspect));
            } else {
                $resizeWidth = \intval(ceil($height * $originalAspect));
                $resizeHeight = $height;
            }
        }

        [$x, $y] = $this->getCropCoordinates(
            $width,
            $height,
            $resizeWidth,
            $resizeHeight,
            $gravity,
            $x,
            $y,
        );

        try {
            $this->resize($width, $height, $resizeWidth, $resizeHeight, $x, $y, $gravity === self::GRAVITY_CENTER && !$hasFocalPoint);
        } catch (\ImagickException $exception) {
            throw self::failure($exception, 'Failed to process image');
        }
        $this->height = $height;
        $this->width = $width;

        return $this;
    }

    /**
     * @throws \ImagickException
     */
    private function resize(int $width, int $height, int $resizeWidth, int $resizeHeight, int $x, int $y, bool $thumbnail): void
    {
        if ($this->image->getNumberImages() > 1) {
            $this->image = $this->image->coalesceImages();

            foreach ($this->image as $frame) {
                if ($thumbnail) {
                    $frame->cropThumbnailImage($width, $height);
                } else {
                    $frame->scaleImage($resizeWidth, $resizeHeight, false);
                    $frame->cropImage($width, $height, $x, $y);
                    $frame->thumbnailImage($width, $height);
                }

                $frame->setImagePage($width, $height, 0, 0);
            }
        } elseif ($thumbnail) {
            $this->image->cropThumbnailImage($width, $height);
        } else {
            $this->image->scaleImage($resizeWidth, $resizeHeight, false);
            $this->image->cropImage($width, $height, $x, $y);
        }
    }

    /**
     * The library's exception for one of Imagick's, by ImageMagick's own
     * error code; $message is for a failure it has no class for.
     */
    private static function failure(\Throwable $exception, string $message, string $missing = ''): ImageException
    {
        return match (true) {
            // CorruptImageError, BlobError
            \in_array($exception->getCode(), [425, 435], true) => new CorruptImageException($exception),
            // ResourceLimitError, CacheError, and ImageError's dimension limit
            \in_array($exception->getCode(), [400, 445], true),
            $exception->getCode() === 465 && \str_contains($exception->getMessage(), 'exceeds limit') => new LimitExceededException($exception),
            // MissingDelegateError
            $exception->getCode() === 420 && $missing !== '' => new UnsupportedFormatException($missing, $exception),
            default => new ImageException($message, 0, $exception),
        };
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function validateColor(string $color): void
    {
        try {
            new ImagickPixel($color);
        } catch (\ImagickPixelException) {
            throw new \InvalidArgumentException('Invalid color: ' . $color);
        }
    }

    private function validateFocalPoint(?float $x, ?float $y): void
    {
        if ($x === null && $y === null) {
            return;
        }

        if ($x === null || $y === null) {
            throw new \InvalidArgumentException('Both focal point coordinates are required');
        }

        if (!is_finite($x) || !is_finite($y) || $x < 0 || $x > 1 || $y < 0 || $y > 1) {
            throw new \InvalidArgumentException('Focal point coordinates must be finite and between 0 and 1');
        }
    }

    /**
     * @return array{int, int}
     */
    private function getCropCoordinates(
        int $width,
        int $height,
        int $resizeWidth,
        int $resizeHeight,
        string $gravity,
        ?float $x,
        ?float $y,
    ): array {
        if ($x !== null && $y !== null) {
            return [
                \intval(max(0, min($resizeWidth - $width, $x * $resizeWidth - $width / 2))),
                \intval(max(0, min($resizeHeight - $height, $y * $resizeHeight - $height / 2))),
            ];
        }

        return match ($gravity) {
            self::GRAVITY_TOP_LEFT => [0, 0],
            self::GRAVITY_TOP => [\intval(($resizeWidth - $width) / 2), 0],
            self::GRAVITY_TOP_RIGHT => [$resizeWidth - $width, 0],
            self::GRAVITY_LEFT => [0, \intval(($resizeHeight - $height) / 2)],
            self::GRAVITY_RIGHT => [$resizeWidth - $width, \intval(($resizeHeight - $height) / 2)],
            self::GRAVITY_BOTTOM_LEFT => [0, $resizeHeight - $height],
            self::GRAVITY_BOTTOM => [\intval(($resizeWidth - $width) / 2), $resizeHeight - $height],
            self::GRAVITY_BOTTOM_RIGHT => [$resizeWidth - $width, $resizeHeight - $height],
            default => [\intval(($resizeWidth - $width) / 2), \intval(($resizeHeight - $height) / 2)],
        };
    }

    /**
     * @param  int  $borderWidth  The size of the border in pixels
     * @param  string  $borderColor  The color of the border in hex format
     *
     * @throws \InvalidArgumentException
     * @throws ImageException
     */
    public function setBorder(int $borderWidth, string $borderColor): self
    {
        if ($borderWidth < 0) {
            throw new \InvalidArgumentException('Border width must not be negative');
        }
        self::validateColor($borderColor);

        $this->borderWidth = $borderWidth;
        $this->borderColor = $borderColor;

        if ($this->cornerRadius !== 0) {
            return $this;
        }
        try {
            $this->image->borderImage($borderColor, $borderWidth, $borderWidth);
        } catch (\ImagickException $exception) {
            throw self::failure($exception, 'Failed to process image');
        }

        return $this;
    }

    /**
     * Applies rounded corners, background to an image
     *
     * @param  int  $cornerRadius:  The radius for the corners
     * @return Image $image: The processed image
     *
     * @throws \InvalidArgumentException
     * @throws ImageException
     */
    public function setBorderRadius(int $cornerRadius): self
    {
        if ($cornerRadius < 0) {
            throw new \InvalidArgumentException('Corner radius must not be negative');
        }

        try {
            $this->round($cornerRadius);
        } catch (\ImagickException | \ImagickDrawException | \ImagickPixelException $exception) {
            throw self::failure($exception, 'Failed to process image');
        }

        return $this;
    }

    /**
     * @throws \ImagickException
     * @throws \ImagickDrawException
     * @throws \ImagickPixelException
     */
    private function round(int $cornerRadius): void
    {
        $mask = new Imagick();
        $mask->newImage($this->width, $this->height, new ImagickPixel('transparent'), 'png');

        $rectwidth = ($this->borderWidth > 0 ? ($this->width - ($this->borderWidth + 1)) : $this->width - 1);
        $rectheight = ($this->borderWidth > 0 ? ($this->height - ($this->borderWidth + 1)) : $this->height - 1);

        $shape = new ImagickDraw();
        $shape->setFillColor(new ImagickPixel('black'));
        $shape->roundRectangle($this->borderWidth, $this->borderWidth, $rectwidth, $rectheight, $cornerRadius, $cornerRadius);

        $mask->drawImage($shape);
        $this->image->compositeImage($mask, Imagick::COMPOSITE_DSTIN, 0, 0);

        if ($this->borderWidth > 0) {
            $bc = new ImagickPixel();
            $bc->setColor($this->borderColor);

            $strokeCanvas = new Imagick();
            $strokeCanvas->newImage($this->width, $this->height, new ImagickPixel('transparent'), 'png');

            $shape2 = new ImagickDraw();
            $shape2->setFillColor(new ImagickPixel('transparent'));
            $shape2->setStrokeWidth($this->borderWidth);
            $shape2->setStrokeColor($bc);
            $shape2->roundRectangle($this->borderWidth, $this->borderWidth, $rectwidth, $rectheight, $cornerRadius, $cornerRadius);

            $strokeCanvas->drawImage($shape2);
            $strokeCanvas->compositeImage($this->image, Imagick::COMPOSITE_DEFAULT, 0, 0);

            $this->image = $strokeCanvas;
        }
    }

    /**
     * @param  float  $opacity  The opacity of the image
     *
     * @throws ImageException
     */
    public function setOpacity(float $opacity): self
    {
        if ($opacity == 1) {
            return $this;
        }
        try {
            $this->image->setImageAlphaChannel(Imagick::ALPHACHANNEL_OPAQUE);
            if ($opacity === 0.0) {
                $this->image->evaluateImage(Imagick::EVALUATE_SET, 0, Imagick::CHANNEL_ALPHA);
            } else {
                $this->image->evaluateImage(Imagick::EVALUATE_DIVIDE, 1 / $opacity, Imagick::CHANNEL_ALPHA);
            }
        } catch (\ImagickException $exception) {
            throw self::failure($exception, 'Failed to process image');
        }

        return $this;
    }

    /**
     * Rotates an image to $degree degree
     *
     * @param  int  $degree:  The amount to rotate in degrees
     * @return Image $image: The rotated image
     *
     * @throws LimitExceededException
     * @throws ImageException
     */
    public function setRotation(int $degree): self
    {
        if ($degree === 0) {
            return $this;
        }

        try {
            $this->image->rotateImage('transparent', $degree);
        } catch (\ImagickException $exception) {
            throw self::failure($exception, 'Failed to process image');
        }

        return $this;
    }

    /**
     * @param  mixed  $color
     *
     * @throws \InvalidArgumentException
     * @throws ImageException
     */
    public function setBackground($color): static
    {
        if (! \is_string($color)) {
            throw new \InvalidArgumentException('Invalid color: ' . \get_debug_type($color));
        }
        self::validateColor($color);

        try {
            $this->image->setImageBackgroundColor($color);
            $this->image = $this->image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        } catch (\ImagickException $exception) {
            throw self::failure($exception, 'Failed to process image');
        }

        return $this;
    }

    /**
     * Output.
     *
     * Prints manipulated image.
     *
     * @throws UnsupportedFormatException
     * @throws ImageException
     */
    public function output(string $type, int $quality = 75): ?string
    {
        return $this->save(null, $type, $quality);
    }

    /**
     * @throws UnsupportedFormatException
     * @throws ImageException
     */
    public function save(?string $path = null, string $type = '', int $quality = 75): ?string
    {
        // Create directory with write permissions
        if ($path !== null && !file_exists(\dirname($path)) && ! @mkdir(\dirname($path), 0755, true)) {
            throw new ImageException('Can\'t create directory ' . \dirname($path));
        }

        try {
            return $this->write($path, $type, $quality);
        } catch (\ImagickException $exception) {
            // Without an encoder ImageMagick fails AVIF and HEIC with no more than an empty blob.
            if (\in_array($type, ['avif', 'heic'], true)) {
                throw new UnsupportedFormatException(\strtoupper($type) . ' output is not available', $exception);
            }
            throw self::failure($exception, 'Failed to write image');
        }
    }

    /**
     * @throws \ImagickException
     * @throws UnsupportedFormatException
     */
    private function write(?string $path, string $type, int $quality): ?string
    {

        // Apply original metadata rotation
        if ($this->rotation !== 0) {
            $this->image->rotateImage('transparent', $this->rotation);
            $this->rotation = 0;
        }

        switch ($type) {
            case 'jpg':
            case 'jpeg':
                if ($quality >= 0) {
                    $this->image->setImageCompressionQuality($quality);
                }

                $this->image->setImageFormat('jpg');
                break;

            case 'gif':
                $this->image->setImageFormat('gif');
                break;

            case 'avif':
            case 'heic':
                $this->image->setImageFormat($type);
                if ($quality >= 0) {
                    // AOM rejects lossless AVIF when chroma delta-Q is enabled
                    // by some libheif/AOM combinations. ImageMagick maps 100
                    // to lossless mode, so keep the highest AVIF quality lossy
                    // for portable encoding.
                    if ($type === 'avif') {
                        $quality = min($quality, 99);
                    }

                    // setImageCompressionQuality() is silently ignored by the libheif coder —
                    // setCompressionQuality() (object-level, not image-level) must be called
                    // after setImageFormat() for quality to take effect on AVIF/HEIC output.
                    // See: https://github.com/Imagick/imagick/issues/711
                    $this->image->setCompressionQuality($quality);
                }
                break;

            case 'webp':
                if ($quality >= 0) {
                    $this->image->setImageCompressionQuality($quality);
                }
                $this->image->setImageFormat('webp');
                break;

            case 'png':
                if ($quality >= 0) {
                    /* Scale quality from 0-100 to 0-9 */
                    $scaleQuality = round(($quality / 100) * 9);
                    /* Invert quality setting as 0 is best, not 9 */
                    $invertScaleQuality = \intval(9 - $scaleQuality);
                    $this->image->setImageCompressionQuality($invertScaleQuality);
                }
                $this->image->setImageFormat('png');
                break;

            default:
                throw new UnsupportedFormatException('Unsupported output format: ' . $type);
        }

        if ($path === null || $path === '') {
            return $this->image->getImagesBlob();
        }
        $this->image->writeImages($path, true);

        return null;
    }

    protected function getSizeByFixedHeight(int $newHeight): int
    {
        $ratio = $this->width / $this->height;
        $newWidth = $newHeight * $ratio;

        return \intval($newWidth);
    }

    protected function getSizeByFixedWidth(int $newWidth): int
    {
        $ratio = $this->height / $this->width;
        $newHeight = $newWidth * $ratio;

        return \intval($newHeight);
    }

    public static function setResourceLimit(string $type, int $value): void
    {
        match ($type) {
            'area' => Imagick::setResourceLimit(Imagick::RESOURCETYPE_AREA, $value),
            'disk' => Imagick::setResourceLimit(Imagick::RESOURCETYPE_DISK, $value),
            'file' => Imagick::setResourceLimit(Imagick::RESOURCETYPE_FILE, $value),
            'map' => Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, $value),
            'memory' => Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, $value),
            'thread' => Imagick::setResourceLimit(Imagick::RESOURCETYPE_THREAD, $value),
            default => null,
        };
    }
}
