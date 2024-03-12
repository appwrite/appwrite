mutation {
    messagingUpdateFcmProvider(
        providerId: "<PROVIDER_ID>",
        name: "<NAME>",
        enabled: false,
        serviceAccountJSON: "{}"
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
