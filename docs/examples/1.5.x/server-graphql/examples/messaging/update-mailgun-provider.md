mutation {
    messagingUpdateMailgunProvider(
        providerId: "<PROVIDER_ID>",
        name: "<NAME>",
        apiKey: "<API_KEY>",
        domain: "<DOMAIN>",
        isEuRegion: false,
        enabled: false,
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
