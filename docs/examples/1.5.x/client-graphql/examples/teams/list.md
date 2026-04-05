query {
    teamsList(
        queries: [],
        search: "<SEARCH>"
    ) {
        total
        teams {
            _id
            _createdAt
            _updatedAt
            name
            total
            prefs {
                data
            }
        }
    }
}
