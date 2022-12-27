mutation {
    functionsCreateExecution(
        functionId: "[FUNCTION_ID]"
    ) {
        id
        createdAt
        updatedAt
        permissions
        functionId
        trigger
        status
        statusCode
        response
        stdout
        stderr
        duration
    }
}
