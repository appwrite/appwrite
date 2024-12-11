query {
    messagingListTopics(
        queries: [],
        search: "<SEARCH>"
    ) {
        total
        topics {
            _id
            _createdAt
            _updatedAt
            name
            emailTotal
            smsTotal
            pushTotal
            subscribe
        }
    }
}
