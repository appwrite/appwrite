mutation {
    messagingCreateApnsProvider(
        providerId: "<PROVIDER_ID>",
        name: "<NAME>",
        authKey: "<AUTH_KEY>",
        authKeyId: "<AUTH_KEY_ID>",
        teamId: "<TEAM_ID>",
        bundleId: "<BUNDLE_ID>",
        sandbox: false,
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
