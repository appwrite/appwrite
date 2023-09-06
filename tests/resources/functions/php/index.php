<?php

return function ($context) {
    return $context->res->json([
        'APPWRITE_FUNCTION_ID' => \getenv('APPWRITE_FUNCTION_ID') ?: '',
        'APPWRITE_FUNCTION_NAME' => \getenv('APPWRITE_FUNCTION_NAME') ?: '',
        'APPWRITE_FUNCTION_DEPLOYMENT' => \getenv('APPWRITE_FUNCTION_DEPLOYMENT') ?: '',
        'APPWRITE_FUNCTION_TRIGGER' => $context->req->headers['x-appwrite-trigger'] ?? '',
        'APPWRITE_FUNCTION_RUNTIME_NAME' => \getenv('APPWRITE_FUNCTION_RUNTIME_NAME') ?: '',
        'APPWRITE_FUNCTION_RUNTIME_VERSION' => \getenv('APPWRITE_FUNCTION_RUNTIME_VERSION') ?: '',
        'UNICODE_TEST' => "êä",
        'GLOBAL_VARIABLE' => \getenv('GLOBAL_VARIABLE') ?: ''
    ]);
};
