mutation {
    messagingCreatePush(
        messageId: "<MESSAGE_ID>",
        title: "<TITLE>",
        body: "<BODY>",
        topics: [],
        users: [],
        targets: [],
        data: "{}",
        action: "<ACTION>",
        image: "[ID1:ID2]",
        icon: "<ICON>",
        sound: "<SOUND>",
        color: "<COLOR>",
        tag: "<TAG>",
        badge: 0,
        draft: false,
        scheduledAt: "",
        contentAvailable: false,
        critical: false,
        priority: "normal"
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
