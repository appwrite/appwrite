query {
    functionsGetExecution(
        functionId: "[FUNCTION_ID]",
        executionId: "[EXECUTION_ID]"
    ) {
        _id
        _createdAt
        _updatedAt
        _permissions
        functionId
        trigger
        status
        requestMethod
        requestPath
        requestHeaders {
            name
            value
        }
        responseStatusCode
        responseBody
        responseHeaders {
            name
            value
        }
        logs
        errors
        duration
    }
}
