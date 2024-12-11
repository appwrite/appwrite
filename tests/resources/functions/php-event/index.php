<?php

return function ($context) {
    $context->log($context->req->body['$id']);
    $context->log($context->req->body['name']);

    return $context->res->empty();
};
