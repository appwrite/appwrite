<?php

/**
 * Symbol stubs for optional extensions, so phpstan can analyse the response
 * on hosts where the extension is not installed. Never autoloaded.
 */

namespace Zstd\Compress {
    if (!\class_exists(Context::class)) {
        final class Context
        {
        }
    }
}

namespace Zstd\UnCompress {
    if (!\class_exists(Context::class)) {
        final class Context
        {
        }
    }
}

namespace {
    if (!\function_exists('zstd_compress_init')) {
        function zstd_compress_init(int $level = 3, ?string $dict = null): Zstd\Compress\Context|false
        {
            return false;
        }
    }

    if (!\function_exists('zstd_compress_add')) {
        function zstd_compress_add(Zstd\Compress\Context $context, string $data, bool $end = false): string|false
        {
            return false;
        }
    }

    if (!\function_exists('zstd_uncompress_init')) {
        function zstd_uncompress_init(?string $dict = null): Zstd\UnCompress\Context|false
        {
            return false;
        }
    }

    if (!\function_exists('zstd_uncompress_add')) {
        function zstd_uncompress_add(Zstd\UnCompress\Context $context, string $data): string|false
        {
            return false;
        }
    }
}
