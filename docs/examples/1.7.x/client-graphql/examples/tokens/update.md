mutation {
    tokensUpdate(
        tokenId: "<TOKEN_ID>",
        expire: "",
        permissions: ["read("any")"]
    ) {
        _id
        _createdAt
        _permissions
        resourceId
        resourceType
        expire
        accessedAt
    }
}
