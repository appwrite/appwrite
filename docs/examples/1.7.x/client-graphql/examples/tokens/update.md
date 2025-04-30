mutation {
    tokensUpdate(
        tokenId: "<TOKEN_ID>",
        expire: "",
        permissions: ["read("any")"]
    ) {
        _id
        _createdAt
        resourceId
        resourceInternalId
        resourceType
        expire
    }
}
