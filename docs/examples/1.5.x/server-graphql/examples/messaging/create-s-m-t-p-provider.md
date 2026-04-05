mutation {
    messagingCreateSMTPProvider(
        providerId: "[PROVIDER_ID]",
        name: "[NAME]",
        host: "[HOST]"
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
