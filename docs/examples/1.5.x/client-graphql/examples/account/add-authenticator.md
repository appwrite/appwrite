mutation {
    accountAddAuthenticator(
        type: "totp"
    ) {
        backups
        secret
        uri
    }
}
