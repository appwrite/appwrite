mutation {
    usersCreateJWT(
        userId: "<USER_ID>",
        sessionId: "<SESSION_ID>",
        duration: 0
    ) {
        jwt
    }
}
