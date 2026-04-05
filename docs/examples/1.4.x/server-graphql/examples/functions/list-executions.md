query {
    functionsListExecutions(
        functionId: "[FUNCTION_ID]"
    ) {
        total
        executions {
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
}
