import json

#    'req' variable has:
#        'headers' - object with request headers
#        'payload' - object with request body data
#        'env' - object with environment variables
#    'res' variable has:
#        'send(text, status)' - function to return text response. Status code defaults to 200
#        'json(obj, status)' - function to return JSON response. Status code defaults to 200
#    
#    If an error is thrown, a response with code 500 will be returned.

def main(request, response):
    return response.json({
        'APPWRITE_FUNCTION_ID' : request.env['APPWRITE_FUNCTION_ID'],
        'APPWRITE_FUNCTION_NAME' : request.env['APPWRITE_FUNCTION_NAME'],
        'APPWRITE_FUNCTION_DEPLOYMENT' : request.env['APPWRITE_FUNCTION_DEPLOYMENT'],
        'APPWRITE_FUNCTION_TRIGGER' : request.env['APPWRITE_FUNCTION_TRIGGER'],
        'APPWRITE_FUNCTION_RUNTIME_NAME' : request.env['APPWRITE_FUNCTION_RUNTIME_NAME'],
        'APPWRITE_FUNCTION_RUNTIME_VERSION' : request.env['APPWRITE_FUNCTION_RUNTIME_VERSION'],
        'APPWRITE_FUNCTION_EVENT' : request.env['APPWRITE_FUNCTION_EVENT'],
        'APPWRITE_FUNCTION_EVENT_DATA' : request.env['APPWRITE_FUNCTION_EVENT_DATA'],
        'APPWRITE_FUNCTION_DATA' : request.env['APPWRITE_FUNCTION_DATA'],
        'APPWRITE_FUNCTION_USER_ID' : request.env['APPWRITE_FUNCTION_USER_ID'],
        'APPWRITE_FUNCTION_JWT' : request.env['APPWRITE_FUNCTION_JWT'],
        'APPWRITE_FUNCTION_PROJECT_ID' : request.env['APPWRITE_FUNCTION_PROJECT_ID'],
        'CUSTOM_VARIABLE' : request.env['CUSTOM_VARIABLE'],
    })