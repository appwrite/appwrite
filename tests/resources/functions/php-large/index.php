<?php

return function ($context) {
    return $context->res->json([
        'APPWRITE_FUNCTION_ID' => \getenv('APPWRITE_FUNCTION_ID') ?: '',
        'APPWRITE_FUNCTION_NAME' => \getenv('APPWRITE_FUNCTION_NAME') ?: '',
        'APPWRITE_FUNCTION_DEPLOYMENT' => \getenv('APPWRITE_FUNCTION_DEPLOYMENT') ?: '',
        'APPWRITE_FUNCTION_TRIGGER' => $context->req->headers['x-appwrite-trigger'] ?? '',
        'APPWRITE_FUNCTION_RUNTIME_NAME' => \getenv('APPWRITE_FUNCTION_RUNTIME_NAME') ?: '',
        'APPWRITE_FUNCTION_RUNTIME_VERSION' => \getenv('APPWRITE_FUNCTION_RUNTIME_VERSION') ?: '',
        'APPWRITE_REGION' => \getenv('APPWRITE_REGION') ?: '',
        'APPWRITE_FUNCTION_CPUS' => \getenv('APPWRITE_FUNCTION_CPUS') ?: '',
        'APPWRITE_FUNCTION_MEMORY' => \getenv('APPWRITE_FUNCTION_MEMORY') ?: '',
        'UNICODE_TEST' => "êä"
    ]);
};
