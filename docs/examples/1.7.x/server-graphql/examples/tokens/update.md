mutation {
    tokensUpdate(
        tokenId: "<TOKEN_ID>",
        expire: ""
    ) {
        _id
        _createdAt
        resourceId
        resourceType
        expire
        secret
        accessedAt
    }
}
