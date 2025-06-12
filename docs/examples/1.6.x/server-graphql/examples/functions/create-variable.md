mutation {
    functionsCreateVariable(
        functionId: "<FUNCTION_ID>",
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
