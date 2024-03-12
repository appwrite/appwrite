mutation {
    messagingUpdateVonageProvider(
        providerId: "<PROVIDER_ID>",
        name: "<NAME>",
        enabled: false,
        apiKey: "<API_KEY>",
        apiSecret: "<API_SECRET>",
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
