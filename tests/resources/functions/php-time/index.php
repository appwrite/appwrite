<?php

return function ($context) {
    return $context->res->json([
        'time' => \time(),
    ]);
};
