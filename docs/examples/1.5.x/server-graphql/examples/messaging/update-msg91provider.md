mutation {
    messagingUpdateMsg91Provider(
        providerId: "<PROVIDER_ID>",
        name: "<NAME>",
        enabled: false,
        senderId: "<SENDER_ID>",
        authKey: "<AUTH_KEY>",
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
