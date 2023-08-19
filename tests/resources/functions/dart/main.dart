import 'dart:io' show Platform;

Future<dynamic> main(final context) async {
    context.log('Amazing Function Log');

    response.json({
        'APPWRITE_FUNCTION_ID' : Platform.environment['APPWRITE_FUNCTION_ID'] ?? '',
        'APPWRITE_FUNCTION_NAME' : Platform.environment['APPWRITE_FUNCTION_NAME'] ?? '',
        'APPWRITE_FUNCTION_DEPLOYMENT' : Platform.environment['APPWRITE_FUNCTION_DEPLOYMENT'] ?? '',
        'APPWRITE_FUNCTION_TRIGGER' : context.req.headers['x-appwrite-trigger'] ?? '',
        'APPWRITE_FUNCTION_RUNTIME_NAME' : Platform.environment['APPWRITE_FUNCTION_RUNTIME_NAME'] ?? '',
        'APPWRITE_FUNCTION_RUNTIME_VERSION' : Platform.environment['APPWRITE_FUNCTION_RUNTIME_VERSION'] ?? '',
        'APPWRITE_FUNCTION_EVENT' : context.req.headers['x-appwrite-event'] ?? '',
        'APPWRITE_FUNCTION_EVENT_DATA' : context.req.bodyRaw ?? '',
        'APPWRITE_FUNCTION_DATA' : context.req.bodyRaw ?? '',
        'APPWRITE_FUNCTION_USER_ID' : context.req.headers['x-appwrite-user-id'] ?? '',
        'APPWRITE_FUNCTION_JWT' : context.req.headers['x-appwrite-user-jwt'] ?? '',
        'APPWRITE_FUNCTION_PROJECT_ID' : Platform.environment['APPWRITE_FUNCTION_PROJECT_ID'] ?? '',
        'CUSTOM_VARIABLE' : request.variables['CUSTOM_VARIABLE']
    });
}