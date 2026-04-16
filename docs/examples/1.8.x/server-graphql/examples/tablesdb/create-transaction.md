mutation {
    tablesDBCreateTransaction(
        ttl: 60
    ) {
        _id
        _createdAt
        _updatedAt
        status
        operations
        expiresAt
    }
}
