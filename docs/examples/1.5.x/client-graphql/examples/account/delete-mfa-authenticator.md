mutation {
    accountDeleteMfaAuthenticator(
        type: "totp",
        otp: "<OTP>"
    ) {
        status
    }
}
