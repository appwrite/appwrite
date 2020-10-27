<?php

namespace Appwrite\Resize;

use Exception;
use Imagick;
use ImagickDraw;
use ImagickPixel;

class Resize
{
    private $image;

    private $width;

    private $height;

    /**
     * @param string $data
     *
     * @throws Exception
     */
    public function __construct(string $data)
    {
        $this->image = new Imagick();

        $this->image->readImageBlob($data);

        $this->width = $this->image->getImageWidth();
        $this->height = $this->image->getImageHeight();
    }

    /**
     * @param int $width
     * @param int $height
     *
     * @return Resize
     *
     * @throws \Throwable
     */
    public function crop(int $width, int $height): self
    {
        $originalAspect = $this->width / $this->height;

        if (empty($width)) {
            $width = $height * $originalAspect;
        }

        if (empty($height)) {
            $height = $width / $originalAspect;
        }

        if (empty($height) && empty($width)) {
            $height = $this->height;
            $width = $this->width;
        }

        if ($this->image->getImageFormat() == 'GIF') {
            $this->image = $this->image->coalesceImages();

            foreach ($this->image as $frame) {
                $frame->cropThumbnailImage($width, $height);
            }

            $this->image->deconstructImages();
        } else {
            $this->image->cropThumbnailImage($width, $height);
        }

        return $this;
    }

    /**
     * @param integer $borderWidth The size of the border in pixels
     * @param string $borderColor The color of the border in hex format
     * 
     * @return Resize
     *
     * @throws \ImagickException
     */
    public function setBorder(int $borderWidth, string $borderColor): self
    {
        $width = $height = $borderWidth;

        $this->image->borderImage($borderColor, $width, $height);

        return $this;
    }

    /**
      * Applies rounded corners, borders and background to an image
      * @param integer $cornerRadius: The radius for the corners
      * @param string $borderColor: A valid HEX string representing the border color
      * @param integer $borderWidth: An integer representing the broder size in pixels
      * @param string $background: A valid HEX string representing the background color
      * @return Resize $image: The processed image
      *
      * @throws \ImagickException
      */
    public function setBorderRadius(int $cornerRadius): self
    {
        $mask = new Imagick(); // create mask image
        $mask->newImage($this->width, $this->height, new ImagickPixel('transparent'), 'png');
        
        $shape1 = new ImagickDraw(); // create the rounded rectangle
        $shape1->setFillColor(new ImagickPixel('black'));
        $shape1->roundRectangle(0, 0, $this->width, $this->height, $cornerRadius, $cornerRadius);
        
        $shape2 = new ImagickDraw(); // create the rounded rectangle
        $shape2->setFillColor(new ImagickPixel('black'));
        $shape2->roundRectangle(0, 20, $this->width, $this->height, $cornerRadius, $cornerRadius);
        
        $mask->drawImage($shape1); // draw the rectangle
        $mask->drawImage($shape2); // draw the rectangle
        
        // apply mask
        $this->image->compositeImage($mask, Imagick::COMPOSITE_DSTIN, 0, 0);
        
        return $this;
    }

    /**
     * @param float opacity The opacity of the image
     * 
     * @return Resize
     *
     * @throws \ImagickException
     */
    public function setOpacity(float $opacity): self
    {
        if(empty($opacity) || $opacity == 1) {
            return $this;
        }

        $this->image->evaluateImage(Imagick::EVALUATE_MULTIPLY, $opacity, Imagick::CHANNEL_ALPHA);

        return $this;
    }

    /**
     * Rotates an image to $degree degree
     * @param integer $degree: The amount to rotate in degrees
     * @return Resize $image: The rotated image
     *
     * @throws \ImagickException
     */
    public function setRotation(int $degree): self
    {
        if (empty($degree) || $degree == 0) {
            return $this;
        }
        
        $this->image->rotateImage('transparent', $degree);
        
        return $this;
    }

    /**
     * @param $color
     *
     * @return Resize
     *
     * @throws \Throwable
     */
    public function setBackground(string $color): self
    {
        $this->image->setImageBackgroundColor($color);
        $this->image = $this->image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);

        return $this;
    }

    /**
     * Output.
     *
     * Prints manipulated image.
     *
     * @param string $type
     * @param int    $quality
     *
     * @return string
     *
     * @throws Exception
     */
    public function output(string $type, int $quality = 75): string
    {
        return $this->save(null, $type, $quality);
    }

    /**
     * @param string $path
     * @param $type
     * @param int $quality
     *
     * @return string
     *
     * @throws Exception
     */
    public function save(string $path = null, string $type = '', int $quality = 75): string
    {
        // Create directory with write permissions
        if (null !== $path && !\file_exists(\dirname($path))) {
            if (!@\mkdir(\dirname($path), 0755, true)) {
                throw new Exception('Can\'t create directory '.\dirname($path));
            }
        }

        switch ($type) {
            case 'jpg':
            case 'jpeg':
                $this->image->setImageCompressionQuality($quality);

                $this->image->setImageFormat('jpg');
                break;

            case 'gif':
                $this->image->setImageFormat('gif');
                break;

            case 'webp':
                try {
                    $this->image->setImageFormat('webp');
                } catch (\Throwable $th) {
                    $signature = $this->image->getImageSignature();
                    $temp = '/tmp/temp-'.$signature.'.'.\strtolower($this->image->getImageFormat());
                    $output = '/tmp/output-'.$signature.'.webp';

                    // save temp
                    $this->image->writeImages($temp, true);

                    // convert temp
                    \exec("cwebp -quiet -metadata none -q $quality $temp -o $output");

                    $data = \file_get_contents($output);

                    //load webp
                    if (empty($path)) {
                        return $data;
                    } else {
                        \file_put_contents($path, $data, LOCK_EX);
                    }

                    $this->image->clear();
                    $this->image->destroy();

                    //delete webp
                    \unlink($output);
                    \unlink($temp);

                    return '';
                }

                break;

            case 'png':
                /* Scale quality from 0-100 to 0-9 */
                $scaleQuality = \round(($quality / 100) * 9);

                /* Invert quality setting as 0 is best, not 9 */
                $invertScaleQuality = 9 - $scaleQuality;

                $this->image->setImageCompressionQuality($invertScaleQuality);

                $this->image->setImageFormat('png');
                break;

            default:
                throw new Exception('Invalid output type given');
                break;
        }

        if (empty($path)) {
            return $this->image->getImagesBlob();
        } else {
            $this->image->writeImages($path, true);
        }

        $this->image->clear();
        $this->image->destroy();

        return '';
    }

    /**
     * @param int $newHeight
     *
     * @return int
     */
    protected function getSizeByFixedHeight(int $newHeight): int
    {
        $ratio = $this->width / $this->height;
        $newWidth = $newHeight * $ratio;

        return $newWidth;
    }

    /**
     * @param int $newWidth
     *
     * @return int
     */
    protected function getSizeByFixedWidth(int $newWidth): int
    {
        $ratio = $this->height / $this->width;
        $newHeight = $newWidth * $ratio;

        return $newHeight;
    }
}