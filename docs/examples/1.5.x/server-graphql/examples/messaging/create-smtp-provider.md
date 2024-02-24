mutation {
    messagingCreateSmtpProvider(
        providerId: "<PROVIDER_ID>",
        name: "<NAME>",
        host: "<HOST>",
        port: 1,
        username: "<USERNAME>",
        password: "<PASSWORD>",
        encryption: "none",
        autoTLS: false,
        mailer: "<MAILER>",
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
