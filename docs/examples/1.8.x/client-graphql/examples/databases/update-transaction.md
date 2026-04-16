mutation {
    databasesUpdateTransaction(
        transactionId: "<TRANSACTION_ID>",
        commit: false,
        rollback: false
    ) {
        _id
        _createdAt
        _updatedAt
        status
        operations
        expiresAt
    }
}
