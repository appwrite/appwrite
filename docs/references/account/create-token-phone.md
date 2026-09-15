Sends the user an SMS with a secret key for creating a session. If the provided user ID has not be registered, a new user will be created. Use the returned user ID and secret and submit a request to the [POST /v1/account/sessions/token](https://appwrite.io/docs/references/cloud/client-web/account#createSession) endpoint to complete the login process. The secret sent to the user's phone is valid for 15 minutes.

A user is limited to 10 active sessions at a time by default. [Learn more about session limits](https://appwrite.io/docs/authentication-security#limits).

Pass the optional `channel` parameter to choose between `sms` and `whatsapp` delivery. It is only accepted when the project's phone OTP channel is set to `whatsapp-sms`; under any other project setting the request is rejected. When it is omitted, the project's phone OTP channel decides where the secret is sent.
