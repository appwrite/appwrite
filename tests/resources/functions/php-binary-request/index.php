<?php

return function ($context) {
    $hash = md5($context->req->bodyBinary);
    return $context->res->send($hash);
};
