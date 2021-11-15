<?php

return function ($request, $response) {
    return $response->json([
        'APPWRITE_FUNCTION_ID' => $request->env['APPWRITE_FUNCTION_ID'],
        'APPWRITE_FUNCTION_NAME' => $request->env['APPWRITE_FUNCTION_NAME'],
        'APPWRITE_FUNCTION_TAG' => $request->env['APPWRITE_FUNCTION_TAG'],
        'APPWRITE_FUNCTION_TRIGGER' => $request->env['APPWRITE_FUNCTION_TRIGGER'],
        'APPWRITE_FUNCTION_RUNTIME_NAME' => $request->env['APPWRITE_FUNCTION_RUNTIME_NAME'],
        'APPWRITE_FUNCTION_RUNTIME_VERSION' => $request->env['APPWRITE_FUNCTION_RUNTIME_VERSION'],
        'APPWRITE_FUNCTION_EVENT' => $request->env['APPWRITE_FUNCTION_EVENT'],
        'APPWRITE_FUNCTION_EVENT_DATA' => $request->env['APPWRITE_FUNCTION_EVENT_DATA'],
        'UNICODE_TEST' => "êä" // TODO: Re-add unicode test to FunctionsCustomServerTest.php
    ]);
};