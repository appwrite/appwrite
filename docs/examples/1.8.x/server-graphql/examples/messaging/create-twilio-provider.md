mutation {
    messagingCreateTwilioProvider(
        providerId: "<PROVIDER_ID>",
        name: "<NAME>",
        from: "+12065550100",
        accountSid: "<ACCOUNT_SID>",
        authToken: "<AUTH_TOKEN>",
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
