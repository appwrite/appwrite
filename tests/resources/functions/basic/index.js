module.exports = async(context) => {
    context.log('log-works');
    context.error('error-log-works');
    
    if(context.req.headers['x-appwrite-user-jwt']) {
      context.log('jwt-is-valid');
    } else {
      context.log('jwt-is-invalid');
    }
    
    if(context.req.path === '/custom-response') {
      const code = +(context.req.query['code'] || '200');
      const body = context.req.query['body'] || '';
      return context.res.send(body, code);
    }
    
    context.log('body-is-' + (context.req.body ?? ''));
    context.log('custom-header-is-' + (context.req.headers['x-custom-header'] ?? ''));
    context.log('method-is-' + (context.req.method ?? '').toLowerCase());
    context.log('path-is-' + (context.req.path ?? ''));
    context.log('user-is-' + (context.req.headers['x-appwrite-user-id'] ?? ''));
    
    const statusCode = context.req.query['code'] || '200';

    return context.res.json({
        'APPWRITE_FUNCTION_ID' : process.env.APPWRITE_FUNCTION_ID ?? '',
        'APPWRITE_FUNCTION_NAME' : process.env.APPWRITE_FUNCTION_NAME ?? '',
        'APPWRITE_FUNCTION_DEPLOYMENT' : process.env.APPWRITE_FUNCTION_DEPLOYMENT ?? '',
        'APPWRITE_FUNCTION_TRIGGER' : context.req.headers['x-appwrite-trigger'] ?? '',
        'APPWRITE_FUNCTION_RUNTIME_NAME' : process.env.APPWRITE_FUNCTION_RUNTIME_NAME,
        'APPWRITE_FUNCTION_RUNTIME_VERSION' : process.env.APPWRITE_FUNCTION_RUNTIME_VERSION,
        'APPWRITE_VERSION' : process.env.APPWRITE_VERSION ?? '',
        'APPWRITE_REGION' : process.env.APPWRITE_REGION ?? '',
        'UNICODE_TEST' : "êä",
        'GLOBAL_VARIABLE' : process.env.GLOBAL_VARIABLE ?? '',
        'APPWRITE_FUNCTION_EVENT' : context.req.headers['x-appwrite-event'] ?? '',
        'APPWRITE_FUNCTION_EVENT_DATA' : context.req.bodyRaw ?? '',
        'APPWRITE_FUNCTION_DATA' : context.req.bodyRaw ?? '',
        'APPWRITE_FUNCTION_USER_ID' : context.req.headers['x-appwrite-user-id'] ?? '',
        'APPWRITE_FUNCTION_JWT' : context.req.headers['x-appwrite-user-jwt'] ?? '',
        'APPWRITE_FUNCTION_PROJECT_ID' : process.env.APPWRITE_FUNCTION_PROJECT_ID,
        'APPWRITE_FUNCTION_MEMORY' : process.env.APPWRITE_FUNCTION_MEMORY,
        'APPWRITE_FUNCTION_CPUS' : process.env.APPWRITE_FUNCTION_CPUS,
        'APPWRITE_FUNCTION_EXECUTION_ID': context.req.headers['x-appwrite-execution-id'] ?? '',
        'APPWRITE_FUNCTION_CLIENT_IP': context.req.headers['x-appwrite-client-ip'] ?? '',
        'CUSTOM_VARIABLE' : process.env.CUSTOM_VARIABLE
    }, +statusCode);
}