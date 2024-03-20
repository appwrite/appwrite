query {
    functionsListVariables(
        functionId: "<FUNCTION_ID>"
    ) {
        total
        variables {
            _id
            _createdAt
            _updatedAt
            key
            value
            resourceType
            resourceId
        }
    }
}
