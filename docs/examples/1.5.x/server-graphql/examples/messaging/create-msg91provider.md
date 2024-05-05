mutation {
    messagingCreateMsg91Provider(
        providerId: "<PROVIDER_ID>",
        name: "<NAME>",
        templateId: "<TEMPLATE_ID>",
        senderId: "<SENDER_ID>",
        authKey: "<AUTH_KEY>",
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
