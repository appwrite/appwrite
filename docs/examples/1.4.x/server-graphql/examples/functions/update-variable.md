mutation {
    functionsUpdateVariable(
        functionId: "[FUNCTION_ID]",
        variableId: "[VARIABLE_ID]",
        key: "[KEY]"
    ) {
        _id
        _createdAt
        _updatedAt
        key
        value
        resourceType
        resourceId
    }
}
