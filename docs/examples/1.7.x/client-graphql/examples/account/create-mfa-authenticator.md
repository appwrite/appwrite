mutation {
    accountCreateMfaAuthenticator(
        type: "totp"
    ) {
        secret
        uri
    }
}
