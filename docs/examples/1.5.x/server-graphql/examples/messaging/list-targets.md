query {
    messagingListTargets(
        messageId: "<MESSAGE_ID>",
        queries: []
    ) {
        total
        targets {
            _id
            _createdAt
            _updatedAt
            name
            userId
            providerId
            providerType
            identifier
        }
    }
}
