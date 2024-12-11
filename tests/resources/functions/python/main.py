import json
import os

def main(context):
    context.log('Amazing Function Log')

    return context.res.json({
        'APPWRITE_FUNCTION_ID' : os.environ.get('APPWRITE_FUNCTION_ID',''),
        'APPWRITE_FUNCTION_NAME' : os.environ.get('APPWRITE_FUNCTION_NAME',''),
        'APPWRITE_FUNCTION_DEPLOYMENT' : os.environ.get('APPWRITE_FUNCTION_DEPLOYMENT',''),
        'APPWRITE_FUNCTION_TRIGGER' : context.req.headers.get('x-appwrite-trigger', ''),
        'APPWRITE_FUNCTION_RUNTIME_NAME' : os.environ.get('APPWRITE_FUNCTION_RUNTIME_NAME',''),
        'APPWRITE_FUNCTION_RUNTIME_VERSION' : os.environ.get('APPWRITE_FUNCTION_RUNTIME_VERSION',''),
        'APPWRITE_FUNCTION_EVENT' : context.req.headers.get('x-appwrite-event', ''),
        'APPWRITE_FUNCTION_EVENT_DATA' : context.req.body_raw,
        'APPWRITE_FUNCTION_DATA' : context.req.body_raw,
        'APPWRITE_FUNCTION_USER_ID' : context.req.headers.get('x-appwrite-user-id', ''),
        'APPWRITE_FUNCTION_JWT' : context.req.headers.get('x-appwrite-user-jwt', ''),
        'APPWRITE_FUNCTION_PROJECT_ID' : os.environ.get('APPWRITE_FUNCTION_PROJECT_ID',''),
        'CUSTOM_VARIABLE' : os.environ.get('CUSTOM_VARIABLE',''),
    })