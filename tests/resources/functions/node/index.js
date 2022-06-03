/*
    'req' variable has:
        'headers' - object with request headers
        'payload' - object with request body data
        'env' - object with environment variables
    'res' variable has:
        'send(text, status)' - function to return text response. Status code defaults to 200
        'json(obj, status)' - function to return JSON response. Status code defaults to 200

    If an error is thrown, a response with code 500 will be returned.
*/

module.exports = async(req, res) => {
    res.json({
        'APPWRITE_FUNCTION_ID' : req.env.APPWRITE_FUNCTION_ID,
        'APPWRITE_FUNCTION_NAME' : req.env.APPWRITE_FUNCTION_NAME,
        'APPWRITE_FUNCTION_DEPLOYMENT' : req.env.APPWRITE_FUNCTION_DEPLOYMENT,
        'APPWRITE_FUNCTION_TRIGGER' : req.env.APPWRITE_FUNCTION_TRIGGER,
        'APPWRITE_FUNCTION_RUNTIME_NAME' : req.env.APPWRITE_FUNCTION_RUNTIME_NAME,
        'APPWRITE_FUNCTION_RUNTIME_VERSION' : req.env.APPWRITE_FUNCTION_RUNTIME_VERSION,
        'APPWRITE_FUNCTION_EVENT' : req.env.APPWRITE_FUNCTION_EVENT,
        'APPWRITE_FUNCTION_EVENT_DATA' : req.env.APPWRITE_FUNCTION_EVENT_DATA,
        'APPWRITE_FUNCTION_DATA' : req.env.APPWRITE_FUNCTION_DATA,
        'APPWRITE_FUNCTION_USER_ID' : req.env.APPWRITE_FUNCTION_USER_ID,
        'APPWRITE_FUNCTION_JWT' : req.env.APPWRITE_FUNCTION_JWT,
        'APPWRITE_FUNCTION_PROJECT_ID' : req.env.APPWRITE_FUNCTION_PROJECT_ID,
        'CUSTOM_VARIABLE' : req.env.CUSTOM_VARIABLE
    });
}