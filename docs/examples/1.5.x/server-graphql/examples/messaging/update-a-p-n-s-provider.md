mutation {
    messagingUpdateAPNSProvider(
        providerId: "[PROVIDER_ID]",
        name: "[NAME]",
        enabled: false,
        authKey: "[AUTH_KEY]",
        authKeyId: "[AUTH_KEY_ID]",
        teamId: "[TEAM_ID]",
        bundleId: "[BUNDLE_ID]"
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
