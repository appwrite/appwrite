module.exports = async(context) => {
    context.log('Amazing Function Log');
    context.log('schedule-delay-is-' + (context.req.headers['x-appwrite-schedule-delay'] ?? ''));
    context.log('scheduled-at-is-' + (context.req.headers['x-appwrite-scheduled-at'] ?? ''));
    context.log('executed-at-is-' + (context.req.headers['x-appwrite-executed-at'] ?? ''));

    return context.res.json({
        'APPWRITE_FUNCTION_ID' : process.env.APPWRITE_FUNCTION_ID ?? '',
        'APPWRITE_FUNCTION_NAME' : process.env.APPWRITE_FUNCTION_NAME ?? '',
        'APPWRITE_FUNCTION_DEPLOYMENT' : process.env.APPWRITE_FUNCTION_DEPLOYMENT ?? '',
        'APPWRITE_FUNCTION_TRIGGER' : context.req.headers['x-appwrite-trigger'] ?? '',
        'APPWRITE_FUNCTION_RUNTIME_NAME' : process.env.APPWRITE_FUNCTION_RUNTIME_NAME,
        'APPWRITE_FUNCTION_RUNTIME_VERSION' : process.env.APPWRITE_FUNCTION_RUNTIME_VERSION,
        'APPWRITE_FUNCTION_EVENT' : context.req.headers['x-appwrite-event'] ?? '',
        'APPWRITE_FUNCTION_EVENT_DATA' : context.req.bodyRaw ?? '',
        'APPWRITE_FUNCTION_DATA' : context.req.bodyRaw ?? '',
        'APPWRITE_FUNCTION_USER_ID' : context.req.headers['x-appwrite-user-id'] ?? '',
        'APPWRITE_FUNCTION_JWT' : context.req.headers['x-appwrite-user-jwt'] ?? '',
        'APPWRITE_FUNCTION_PROJECT_ID' : process.env.APPWRITE_FUNCTION_PROJECT_ID,
        'APPWRITE_FUNCTION_MEMORY' : process.env.APPWRITE_FUNCTION_MEMORY,
        'APPWRITE_FUNCTION_CPUS' : process.env.APPWRITE_FUNCTION_CPUS,
        'CUSTOM_VARIABLE' : process.env.CUSTOM_VARIABLE
    });
}