mutation {
    teamsUpdateName(
        teamId: "<TEAM_ID>",
        name: "<NAME>"
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
