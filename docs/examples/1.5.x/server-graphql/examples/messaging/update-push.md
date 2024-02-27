mutation {
    messagingUpdatePush(
        messageId: "<MESSAGE_ID>",
        topics: [],
        users: [],
        targets: [],
        title: "<TITLE>",
        body: "<BODY>",
        data: "{}",
        action: "<ACTION>",
        image: "[ID1:ID2]",
        icon: "<ICON>",
        sound: "<SOUND>",
        color: "<COLOR>",
        tag: "<TAG>",
        badge: 0,
        draft: false,
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
