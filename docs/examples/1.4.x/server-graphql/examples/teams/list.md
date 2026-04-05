query {
    teamsList {
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
