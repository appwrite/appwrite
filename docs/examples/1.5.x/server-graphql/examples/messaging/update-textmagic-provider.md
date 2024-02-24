mutation {
    messagingUpdateTextmagicProvider(
        providerId: "<PROVIDER_ID>",
        name: "<NAME>",
        enabled: false,
        username: "<USERNAME>",
        apiKey: "<API_KEY>",
        from: "<FROM>"
    ) {
        _id
        _createdAt
        _updatedAt
        name
        provider
        enabled
        type
        credentials
        options
    }
}
