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
            statusCode
            response
            stdout
            stderr
            duration
        }
    }
}
