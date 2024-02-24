mutation {
    teamsCreate(
        teamId: "<TEAM_ID>",
        name: "<NAME>",
        roles: []
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
