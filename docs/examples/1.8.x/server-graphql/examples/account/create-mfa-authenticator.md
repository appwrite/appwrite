mutation {
    accountCreateMFAAuthenticator(
        type: "totp"
    ) {
        secret
        uri
    }
}
