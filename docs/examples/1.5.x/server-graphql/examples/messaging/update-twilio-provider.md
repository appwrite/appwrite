mutation {
    messagingUpdateTwilioProvider(
        providerId: "<PROVIDER_ID>",
        name: "<NAME>",
        enabled: false,
        accountSid: "<ACCOUNT_SID>",
        authToken: "<AUTH_TOKEN>",
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
