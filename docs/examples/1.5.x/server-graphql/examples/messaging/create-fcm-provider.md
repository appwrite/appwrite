mutation {
    messagingCreateFcmProvider(
        providerId: "<PROVIDER_ID>",
        name: "<NAME>",
        serviceAccountJSON: "{}",
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
