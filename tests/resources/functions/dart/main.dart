/*
    'req' variable has:
        'headers' - object with request headers
        'payload' - object with request body data
        'variables' - object with function variables
    'res' variable has:
        'send(text, status)' - function to return text response. Status code defaults to 200
        'json(obj, status)' - function to return JSON response. Status code defaults to 200
    
    If an error is thrown, a response with code 500 will be returned.
*/

Future<void> start(final request, final response) async {
    response.json({
        'APPWRITE_FUNCTION_ID' : request.variables['APPWRITE_FUNCTION_ID'],
        'APPWRITE_FUNCTION_NAME' : request.variables['APPWRITE_FUNCTION_NAME'],
        'APPWRITE_FUNCTION_DEPLOYMENT' : request.variables['APPWRITE_FUNCTION_DEPLOYMENT'],
        'APPWRITE_FUNCTION_TRIGGER' : request.variables['APPWRITE_FUNCTION_TRIGGER'],
        'APPWRITE_FUNCTION_RUNTIME_NAME' : request.variables['APPWRITE_FUNCTION_RUNTIME_NAME'],
        'APPWRITE_FUNCTION_RUNTIME_VERSION' : request.variables['APPWRITE_FUNCTION_RUNTIME_VERSION'],
        'APPWRITE_FUNCTION_EVENT' : request.variables['APPWRITE_FUNCTION_EVENT'],
        'APPWRITE_FUNCTION_EVENT_DATA' : request.variables['APPWRITE_FUNCTION_EVENT_DATA'],
        'APPWRITE_FUNCTION_DATA' : request.variables['APPWRITE_FUNCTION_DATA'],
        'APPWRITE_FUNCTION_USER_ID' : request.variables['APPWRITE_FUNCTION_USER_ID'],
        'APPWRITE_FUNCTION_JWT' : request.variables['APPWRITE_FUNCTION_JWT'],
        'APPWRITE_FUNCTION_PROJECT_ID' : request.variables['APPWRITE_FUNCTION_PROJECT_ID'],
        'CUSTOM_VARIABLE' : request.variables['CUSTOM_VARIABLE']
    });
}