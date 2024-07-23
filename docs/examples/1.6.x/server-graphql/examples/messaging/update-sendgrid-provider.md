mutation {
    messagingUpdateSendgridProvider(
        providerId: "<PROVIDER_ID>",
        name: "<NAME>",
        enabled: false,
        apiKey: "<API_KEY>",
        fromName: "<FROM_NAME>",
        fromEmail: "email@example.com",
        replyToName: "<REPLY_TO_NAME>",
        replyToEmail: "<REPLY_TO_EMAIL>"
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
