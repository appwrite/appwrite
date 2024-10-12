mutation {
    messagingUpdateMsg91Provider(
        providerId: "<PROVIDER_ID>",
        name: "<NAME>",
        enabled: false,
        templateId: "<TEMPLATE_ID>",
        senderId: "<SENDER_ID>",
        authKey: "<AUTH_KEY>"
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
