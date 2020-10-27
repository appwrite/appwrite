<?php

namespace Appwrite\Resize;

use Exception;
use Imagick;

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
    public function __construct($data)
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
    public function crop(int $width, int $height)
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
     * @param $color
     *
     * @return Resize
     *
     * @throws \Throwable
     */
    public function setBackground($color)
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
    public function output(string $type, int $quality = 75)
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
    public function save(string $path = null, string $type = '', int $quality = 75)
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

                    return;
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
    }

    /**
     * @param int $newHeight
     *
     * @return int
     */
    protected function getSizeByFixedHeight(int $newHeight):int
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
    protected function getSizeByFixedWidth(int $newWidth):int
    {
        $ratio = $this->height / $this->width;
        $newHeight = $newWidth * $ratio;

        return $newHeight;
    }
}