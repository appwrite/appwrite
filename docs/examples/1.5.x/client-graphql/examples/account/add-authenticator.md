mutation {
    accountAddAuthenticator(
        factor: "totp"
    ) {
        backups
        secret
        uri
    }
}
