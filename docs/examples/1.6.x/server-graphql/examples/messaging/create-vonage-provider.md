mutation {
    messagingCreateVonageProvider(
        providerId: "<PROVIDER_ID>",
        name: "<NAME>",
        from: "+12065550100",
        apiKey: "<API_KEY>",
        apiSecret: "<API_SECRET>",
        enabled: false
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
