<?php

return function ($context) {
    $context->log('Amazing Function Log');

    return $context->res->json([
        'APPWRITE_FUNCTION_ID' => \getenv('APPWRITE_FUNCTION_ID') ?: '',
        'APPWRITE_FUNCTION_NAME' => \getenv('APPWRITE_FUNCTION_NAME') ?: '',
        'APPWRITE_FUNCTION_DEPLOYMENT' => \getenv('APPWRITE_FUNCTION_DEPLOYMENT') ?: '',
        'APPWRITE_FUNCTION_TRIGGER' => $context->req->headers['x-appwrite-trigger'] ?? '',
        'APPWRITE_FUNCTION_RUNTIME_NAME' => \getenv('APPWRITE_FUNCTION_RUNTIME_NAME') ?: '',
        'APPWRITE_FUNCTION_RUNTIME_VERSION' => \getenv('APPWRITE_FUNCTION_RUNTIME_VERSION') ?: '',
        'APPWRITE_FUNCTION_EVENT' => $context->req->headers['x-appwrite-event'] ?? '',
        'APPWRITE_FUNCTION_EVENT_DATA' => $context->req->bodyRaw ?? '',
        'APPWRITE_FUNCTION_DATA' => $context->req->bodyRaw ?? '',
        'APPWRITE_FUNCTION_USER_ID' => $context->req->headers['x-appwrite-user-id'] ?? '',
        'APPWRITE_FUNCTION_JWT' =>  $context->req->headers['x-appwrite-user-jwt'] ?? '',
        'APPWRITE_FUNCTION_PROJECT_ID' => \getenv('APPWRITE_FUNCTION_PROJECT_ID') ?: '',
        'CUSTOM_VARIABLE' => \getenv('CUSTOM_VARIABLE') ?: '',
    ]);
};
