mutation {
    teamsUpdate(
        teamId: "[TEAM_ID]",
        name: "[NAME]"
    ) {
        _id
        _createdAt
        _updatedAt
        name
        total
    }
}
