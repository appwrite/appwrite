mutation {
    functionsUpdateVariable(
        functionId: "<FUNCTION_ID>",
        variableId: "<VARIABLE_ID>",
        key: "<KEY>",
        value: "<VALUE>"
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
