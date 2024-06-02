<?php

return function ($context) {
    return $context->res->send($context->req->headers['cookie'] ?? '');
};
