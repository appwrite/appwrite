mutation {
    messagingCreateMailgunProvider(
        providerId: "<PROVIDER_ID>",
        name: "<NAME>",
        apiKey: "<API_KEY>",
        domain: "<DOMAIN>",
        isEuRegion: false,
        fromName: "<FROM_NAME>",
        fromEmail: "email@example.com",
        replyToName: "<REPLY_TO_NAME>",
        replyToEmail: "email@example.com",
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
