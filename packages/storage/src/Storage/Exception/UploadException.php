<?php

declare(strict_types=1);

namespace Utopia\Storage\Exception;

/**
 * A chunked upload is in an invalid state: a chunk is missing, the multipart
 * upload was never prepared, or the upload could not be finalized.
 */
class UploadException extends StorageException {}
