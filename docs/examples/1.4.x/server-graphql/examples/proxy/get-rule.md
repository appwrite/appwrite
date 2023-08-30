query {
    proxyGetRule(
        ruleId: "[RULE_ID]"
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
