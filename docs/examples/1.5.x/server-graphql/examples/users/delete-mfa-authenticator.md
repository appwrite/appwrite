mutation {
    usersDeleteMfaAuthenticator(
        userId: "<USER_ID>",
        type: "totp"
    ) {
        status
    }
}
