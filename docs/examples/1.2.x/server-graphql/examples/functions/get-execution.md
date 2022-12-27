query {
    functionsGetExecution(
        functionId: "[FUNCTION_ID]",
        executionId: "[EXECUTION_ID]"
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
