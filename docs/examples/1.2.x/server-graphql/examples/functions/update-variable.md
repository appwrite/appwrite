mutation {
    functionsUpdateVariable(
        functionId: "[FUNCTION_ID]",
        variableId: "[VARIABLE_ID]",
        key: "[KEY]"
    ) {
        id
        createdAt
        updatedAt
        key
        value
        functionId
    }
}
