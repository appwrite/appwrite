<?php

return function ($context) {
    $context->log($context->req->headers);
    return $context->res->send($context->req->headers['cookie'] ?? '');
};
