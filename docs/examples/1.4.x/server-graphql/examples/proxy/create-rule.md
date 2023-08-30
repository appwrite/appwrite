mutation {
    proxyCreateRule(
        domain: "",
        resourceType: "api"
    ) {
        _id
        _createdAt
        _updatedAt
        domain
        resourceType
        resourceId
        status
        logs
        renewAt
    }
}
