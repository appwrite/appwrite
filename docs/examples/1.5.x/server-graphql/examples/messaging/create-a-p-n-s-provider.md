mutation {
    messagingCreateAPNSProvider(
        providerId: "[PROVIDER_ID]",
        name: "[NAME]"
    ) {
        _id
        _createdAt
        _updatedAt
        name
        provider
        enabled
        type
        credentials
    }
}
