query {
    sitesGetVariable(
        siteId: "<SITE_ID>",
        variableId: "<VARIABLE_ID>"
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
