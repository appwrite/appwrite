query {
    messagingListProviders(
        queries: [],
        search: "<SEARCH>"
    ) {
        total
        providers {
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
}
