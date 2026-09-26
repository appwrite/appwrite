Begin the process of MFA verification after sign-in. Finish the flow with [updateMfaChallenge](/docs/references/cloud/client-web/account#updateMfaChallenge) method.

Challenges are valid for 1 hour by default. Customize this period with the optional `expire` parameter (in seconds). The optional `length` parameter sets the number of digits in email and SMS verification codes, which defaults to 6. It does not change TOTP, recovery codes, or custom challenges.

Client requests allow `length` from 6 to 128 digits for email and phone factors, and `expire` from 60 to 3600 seconds for all factors. Requests with privileged administrator permissions and access to this endpoint allow `length` from 4 to 128 digits and `expire` from 60 to 31536000 seconds (1 year).
