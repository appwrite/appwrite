def main(context)
    context.log('Amazing Function Log')

    return context.res.json({
        'APPWRITE_FUNCTION_ID' => ENV['APPWRITE_FUNCTION_ID'] || '',
        'APPWRITE_FUNCTION_NAME' => ENV['APPWRITE_FUNCTION_NAME'] || '',
        'APPWRITE_FUNCTION_DEPLOYMENT' => ENV['APPWRITE_FUNCTION_DEPLOYMENT'] || '',
        'APPWRITE_FUNCTION_TRIGGER' => context.req.headers['x-appwrite-trigger'] || '',
        'APPWRITE_FUNCTION_RUNTIME_NAME' => ENV['APPWRITE_FUNCTION_RUNTIME_NAME'] || '',
        'APPWRITE_FUNCTION_RUNTIME_VERSION' => ENV['APPWRITE_FUNCTION_RUNTIME_VERSION'] || '',
        'APPWRITE_FUNCTION_EVENT' => context.req.headers['x-appwrite-event'] || '',
        'APPWRITE_FUNCTION_EVENT_DATA' => context.req.body_raw || '',
        'APPWRITE_FUNCTION_DATA' => context.req.body_raw || '',
        'APPWRITE_FUNCTION_USER_ID' => context.req.headers['x-appwrite-user-id'] || '',
        'APPWRITE_FUNCTION_JWT' => context.req.headers['x-appwrite-user-jwt'] || '',
        'APPWRITE_FUNCTION_PROJECT_ID' => ENV['APPWRITE_FUNCTION_PROJECT_ID'] || '',
        'CUSTOM_VARIABLE' => ENV['CUSTOM_VARIABLE'] || ''
    })
end