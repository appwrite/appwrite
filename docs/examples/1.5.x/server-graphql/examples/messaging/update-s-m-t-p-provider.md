mutation {
    messagingUpdateSMTPProvider(
        providerId: "[PROVIDER_ID]"
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
