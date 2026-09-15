Begin the process of MFA verification after sign-in. Finish the flow with [updateMfaChallenge](/docs/references/cloud/client-web/account#updateMfaChallenge) method.

Challenges are valid for 1 hour by default. Customize this period with the optional `expire` parameter (in seconds). The optional `length` parameter sets the number of digits in email and SMS verification codes, which defaults to 6. It does not change TOTP, recovery codes, or custom challenges.
