mutation {
    teamsCreateMembership(
        teamId: "<TEAM_ID>",
        roles: [],
        email: "email@example.com",
        userId: "<USER_ID>",
        phone: "+12065550100",
        url: "https://example.com",
        name: "<NAME>"
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
        mfa
        roles
    }
}
