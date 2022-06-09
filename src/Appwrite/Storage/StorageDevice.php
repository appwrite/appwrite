<?php

namespace Appwrite\Storage;

use Utopia\App;
use Utopia\Storage\Device;
use Utopia\Storage\Storage;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\S3;

class StorageDevice
{
    /**
     * Get Functions Storage Device
     * @param string $projectId of the project
     * @return Device
     */
    public static function getFunctionsDevice($projectId): Device
    {
        return self::getDevice(APP_STORAGE_FUNCTIONS . '/app-' . $projectId);
    }

    /**
     * Get Files Storage Device
     * @param string $projectId of the project
     * @return Device
     */
    public static function getFilesDevice($projectId): Device
    {
        return self::getDevice(APP_STORAGE_UPLOADS . '/app-' . $projectId);
    }

    /**
     * Get Builds Storage Device
     * @param string $projectId of the project
     * @return Device
     */
    public static function getBuildsDevice($projectId): Device
    {
        return self::getDevice(APP_STORAGE_BUILDS . '/app-' . $projectId);
    }

    /**
     * Get Device based on selected storage environment
     * @param string $root path of the device
     * @return Device
     */
    public static function getDevice($root): Device
    {
        switch (App::getEnv('_APP_STORAGE_DEVICE', Storage::DEVICE_LOCAL)) {
            case Storage::DEVICE_LOCAL:
            default:
                return new Local($root);
            case Storage::DEVICE_S3:
                $s3AccessKey = App::getEnv('_APP_STORAGE_S3_ACCESS_KEY', '');
                $s3SecretKey = App::getEnv('_APP_STORAGE_S3_SECRET', '');
                $s3Region = App::getEnv('_APP_STORAGE_S3_REGION', '');
                $s3Bucket = App::getEnv('_APP_STORAGE_S3_BUCKET', '');
                $s3Acl = 'private';
                return new S3($root, $s3AccessKey, $s3SecretKey, $s3Bucket, $s3Region, $s3Acl);
            case Storage::DEVICE_DO_SPACES:
                $doSpacesAccessKey = App::getEnv('_APP_STORAGE_DO_SPACES_ACCESS_KEY', '');
                $doSpacesSecretKey = App::getEnv('_APP_STORAGE_DO_SPACES_SECRET', '');
                $doSpacesRegion = App::getEnv('_APP_STORAGE_DO_SPACES_REGION', '');
                $doSpacesBucket = App::getEnv('_APP_STORAGE_DO_SPACES_BUCKET', '');
                $doSpacesAcl = 'private';
                return new DOSpaces($root, $doSpacesAccessKey, $doSpacesSecretKey, $doSpacesBucket, $doSpacesRegion, $doSpacesAcl);
            case Storage::DEVICE_BACKBLAZE:
                $backblazeAccessKey = App::getEnv('_APP_STORAGE_BACKBLAZE_ACCESS_KEY', '');
                $backblazeSecretKey = App::getEnv('_APP_STORAGE_BACKBLAZE_SECRET', '');
                $backblazeRegion = App::getEnv('_APP_STORAGE_BACKBLAZE_REGION', '');
                $backblazeBucket = App::getEnv('_APP_STORAGE_BACKBLAZE_BUCKET', '');
                $backblazeAcl = 'private';
                return new Backblaze($root, $backblazeAccessKey, $backblazeSecretKey, $backblazeBucket, $backblazeRegion, $backblazeAcl);
            case Storage::DEVICE_LINODE:
                $linodeAccessKey = App::getEnv('_APP_STORAGE_LINODE_ACCESS_KEY', '');
                $linodeSecretKey = App::getEnv('_APP_STORAGE_LINODE_SECRET', '');
                $linodeRegion = App::getEnv('_APP_STORAGE_LINODE_REGION', '');
                $linodeBucket = App::getEnv('_APP_STORAGE_LINODE_BUCKET', '');
                $linodeAcl = 'private';
                return new Linode($root, $linodeAccessKey, $linodeSecretKey, $linodeBucket, $linodeRegion, $linodeAcl);
            case Storage::DEVICE_WASABI:
                $wasabiAccessKey = App::getEnv('_APP_STORAGE_WASABI_ACCESS_KEY', '');
                $wasabiSecretKey = App::getEnv('_APP_STORAGE_WASABI_SECRET', '');
                $wasabiRegion = App::getEnv('_APP_STORAGE_WASABI_REGION', '');
                $wasabiBucket = App::getEnv('_APP_STORAGE_WASABI_BUCKET', '');
                $wasabiAcl = 'private';
                return new Wasabi($root, $wasabiAccessKey, $wasabiSecretKey, $wasabiBucket, $wasabiRegion, $wasabiAcl);
        }
    }
}
