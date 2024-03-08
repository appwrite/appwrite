mutation {
    messagingCreateEmail(
        messageId: "<MESSAGE_ID>",
        subject: "<SUBJECT>",
        content: "<CONTENT>",
        topics: [],
        users: [],
        targets: [],
        cc: [],
        bcc: [],
        attachments: [],
        draft: false,
        html: false,
        scheduledAt: ""
    ) {
        _id
        _createdAt
        _updatedAt
        providerType
        topics
        users
        targets
        scheduledAt
        deliveredAt
        deliveryErrors
        deliveredTotal
        data
        status
    }
}
