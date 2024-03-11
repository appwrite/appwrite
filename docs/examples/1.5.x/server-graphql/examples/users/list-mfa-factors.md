query {
    usersListMfaFactors(
        userId: "<USER_ID>"
    ) {
        totp
        phone
        email
    }
}
