query {
    sitesListVariables(
        siteId: "<SITE_ID>"
    ) {
        total
        variables {
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
}
