mutation {
    teamsCreate(
        teamId: "[TEAM_ID]",
        name: "[NAME]"
    ) {
        id
        createdAt
        updatedAt
        name
        total
    }
}