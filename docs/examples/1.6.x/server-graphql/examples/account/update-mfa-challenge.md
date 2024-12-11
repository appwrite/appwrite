mutation {
    accountUpdateMfaChallenge(
        challengeId: "<CHALLENGE_ID>",
        otp: "<OTP>"
    ) {
        status
    }
}
