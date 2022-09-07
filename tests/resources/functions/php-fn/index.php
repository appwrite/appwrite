<?php

return function ($request, $response) {
    \var_dump("Amazing Function Log"); // We test logs (stdout) visibility with this

    $response->json([
        'APPWRITE_FUNCTION_ID' => $request['env']['APPWRITE_FUNCTION_ID'],
        'APPWRITE_FUNCTION_NAME' => $request['env']['APPWRITE_FUNCTION_NAME'],
        'APPWRITE_FUNCTION_DEPLOYMENT' => $request['env']['APPWRITE_FUNCTION_DEPLOYMENT'],
        'APPWRITE_FUNCTION_TRIGGER' => $request['env']['APPWRITE_FUNCTION_TRIGGER'],
        'APPWRITE_FUNCTION_RUNTIME_NAME' => $request['env']['APPWRITE_FUNCTION_RUNTIME_NAME'],
        'APPWRITE_FUNCTION_RUNTIME_VERSION' => $request['env']['APPWRITE_FUNCTION_RUNTIME_VERSION'],
        'APPWRITE_FUNCTION_EVENT' => $request['env']['APPWRITE_FUNCTION_EVENT'],
        'APPWRITE_FUNCTION_EVENT_DATA' => $request['env']['APPWRITE_FUNCTION_EVENT_DATA'],
        'APPWRITE_FUNCTION_DATA' => $request['env']['APPWRITE_FUNCTION_DATA'],
        'APPWRITE_FUNCTION_USER_ID' => $request['env']['APPWRITE_FUNCTION_USER_ID'],
        'APPWRITE_FUNCTION_JWT' => $request['env']['APPWRITE_FUNCTION_JWT'],
        'APPWRITE_FUNCTION_PROJECT_ID' => $request['env']['APPWRITE_FUNCTION_PROJECT_ID'],
    ]);
};
