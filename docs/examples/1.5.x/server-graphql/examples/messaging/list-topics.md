query {
    messagingListTopics {
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
