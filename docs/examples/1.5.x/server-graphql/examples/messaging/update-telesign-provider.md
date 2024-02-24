mutation {
    messagingUpdateTelesignProvider(
        providerId: "<PROVIDER_ID>",
        name: "<NAME>",
        enabled: false,
        customerId: "<CUSTOMER_ID>",
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
