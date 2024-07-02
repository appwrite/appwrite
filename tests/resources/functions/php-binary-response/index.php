<?php

return function ($context) {
    $bytes = pack('C*', ...[0, 10, 255]);
    return $context->res->binary($bytes);
};
