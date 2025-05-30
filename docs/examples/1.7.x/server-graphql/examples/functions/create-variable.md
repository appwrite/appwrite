mutation {
    functionsCreateVariable(
        functionId: "<FUNCTION_ID>",
        key: "<KEY>",
        value: "<VALUE>",
        secret: false
    ) {
        _id
        _createdAt
        _updatedAt
        key
        value
        secret
        resourceType
        resourceId
    }
}
