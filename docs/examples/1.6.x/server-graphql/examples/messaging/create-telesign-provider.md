mutation {
    messagingCreateTelesignProvider(
        providerId: "<PROVIDER_ID>",
        name: "<NAME>",
        from: "+12065550100",
        customerId: "<CUSTOMER_ID>",
        apiKey: "<API_KEY>",
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
