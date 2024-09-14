<?php

return function ($context) {
    $context->log('body-is-' . ($context->req->body ?? ''));
    $context->log('custom-header-is-' . ($context->req->headers['x-custom-header'] ?? ''));
    $context->log('method-is-' . \strtolower($context->req->method ?? ''));
    $context->log('path-is-' . ($context->req->path ?? ''));
    $context->log('user-is-' . $context->req->headers['x-appwrite-user-id'] ?? '');

    if (empty($context->req->headers['x-appwrite-user-jwt'] ?? '')) {
        $context->log('jwt-is-invalid');
    } else {
        $context->log('jwt-is-valid');
    }

    $context->error('error-log-works');

    $statusCode = $context->req->query['code'] ?? '200';

    return $context->res->json([
        'APPWRITE_FUNCTION_ID' => \getenv('APPWRITE_FUNCTION_ID') ?: '',
        'APPWRITE_FUNCTION_NAME' => \getenv('APPWRITE_FUNCTION_NAME') ?: '',
        'APPWRITE_FUNCTION_DEPLOYMENT' => \getenv('APPWRITE_FUNCTION_DEPLOYMENT') ?: '',
        'APPWRITE_FUNCTION_TRIGGER' => $context->req->headers['x-appwrite-trigger'] ?? '',
        'APPWRITE_FUNCTION_RUNTIME_NAME' => \getenv('APPWRITE_FUNCTION_RUNTIME_NAME') ?: '',
        'APPWRITE_FUNCTION_RUNTIME_VERSION' => \getenv('APPWRITE_FUNCTION_RUNTIME_VERSION') ?: '',
        'APPWRITE_REGION' => \getenv('APPWRITE_REGION') ?: '',
        'UNICODE_TEST' => "êä",
        'GLOBAL_VARIABLE' => \getenv('GLOBAL_VARIABLE') ?: '',
        'APPWRITE_FUNCTION_CPUS' => \getenv('APPWRITE_FUNCTION_CPUS') ?: '',
        'APPWRITE_FUNCTION_MEMORY' => \getenv('APPWRITE_FUNCTION_MEMORY') ?: '',
    ], \intval($statusCode));
};
