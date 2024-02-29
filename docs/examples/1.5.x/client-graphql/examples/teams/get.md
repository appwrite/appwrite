query {
    teamsGet(
        teamId: "<TEAM_ID>"
    ) {
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
