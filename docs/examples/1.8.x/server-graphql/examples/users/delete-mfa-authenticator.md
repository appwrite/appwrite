mutation {
    usersDeleteMFAAuthenticator(
        userId: "<USER_ID>",
        type: "totp"
    ) {
        status
    }
}
