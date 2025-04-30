query {
    usersListMemberships(
        userId: "<USER_ID>",
        queries: [],
        search: "<SEARCH>"
    ) {
        total
        memberships {
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
}
