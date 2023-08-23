<?php

return function ($request, $response) {
    return $response->json([
        'APPWRITE_FUNCTION_ID' => $request['variables']['APPWRITE_FUNCTION_ID'],
        'APPWRITE_FUNCTION_NAME' => $request['variables']['APPWRITE_FUNCTION_NAME'],
        'APPWRITE_FUNCTION_DEPLOYMENT' => $request['variables']['APPWRITE_FUNCTION_DEPLOYMENT'],
        'APPWRITE_FUNCTION_TRIGGER' => $request['variables']['APPWRITE_FUNCTION_TRIGGER'],
        'APPWRITE_FUNCTION_RUNTIME_NAME' => $request['variables']['APPWRITE_FUNCTION_RUNTIME_NAME'],
        'APPWRITE_FUNCTION_RUNTIME_VERSION' => $request['variables']['APPWRITE_FUNCTION_RUNTIME_VERSION'],
        'APPWRITE_FUNCTION_EVENT' => $request['variables']['APPWRITE_FUNCTION_EVENT'],
        'APPWRITE_FUNCTION_EVENT_DATA' => $request['variables']['APPWRITE_FUNCTION_EVENT_DATA'],
        'UNICODE_TEST' => 'êä',
    ]);
};
