mutation {
    functionsCreateExecution(
        functionId: "[FUNCTION_ID]"
    ) {
        _id
        _createdAt
        _updatedAt
        _permissions
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
