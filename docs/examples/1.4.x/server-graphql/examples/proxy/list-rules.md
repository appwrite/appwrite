query {
    proxyListRules {
        total
        rules {
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
}
