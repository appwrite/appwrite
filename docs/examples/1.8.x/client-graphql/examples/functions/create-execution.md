mutation {
    functionsCreateExecution(
        functionId: "<FUNCTION_ID>",
        body: "<BODY>",
        async: false,
        path: "<PATH>",
        method: "GET",
        headers: "{}",
        scheduledAt: "<SCHEDULED_AT>"
    ) {
        _id
        _createdAt
        _updatedAt
        _permissions
        functionId
        deploymentId
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
        scheduledAt
    }
}
