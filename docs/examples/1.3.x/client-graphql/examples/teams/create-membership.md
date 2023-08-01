mutation {
    teamsCreateMembership(
        teamId: "[TEAM_ID]",
        roles: [],
        url: "https://example.com"
    ) {
        _id
        _createdAt
        _updatedAt
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
