mutation {
    teamsCreateMembership(
        teamId: "[TEAM_ID]",
        email: "email@example.com",
        roles: [],
        url: "https://example.com"
    ) {
        id
        createdAt
        updatedAt
        userId
        userName
        userEmail
        teamId
        teamName
        invited
        joined
        confirm
        roles
    }
}
