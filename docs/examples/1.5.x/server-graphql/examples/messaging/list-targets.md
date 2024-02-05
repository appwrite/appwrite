query {
    messagingListTargets(
        messageId: "[MESSAGE_ID]"
    ) {
        total
        targets {
            _id
            _createdAt
            _updatedAt
            name
            userId
            providerType
            identifier
        }
    }
}
