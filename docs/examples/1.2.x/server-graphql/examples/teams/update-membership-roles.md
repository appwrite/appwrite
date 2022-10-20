mutation {
    teamsUpdateMembershipRoles(
        teamId: "[TEAM_ID]",
        membershipId: "[MEMBERSHIP_ID]",
        roles: []
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