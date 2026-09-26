Sends the user an SMS with a secret key for creating a session. If the provided user ID has not be registered, a new user will be created. Use the returned user ID and secret and submit a request to the [POST /v1/account/sessions/token](https://appwrite.io/docs/references/cloud/client-web/account#createSession) endpoint to complete the login process. The code has 6 digits and is valid for 15 minutes by default. Customize its length with the optional `length` parameter and its lifetime in seconds with `expire`. Configured mock phone numbers keep their predefined codes.

Client requests allow `length` from 6 to 128 digits and `expire` from 60 to 900 seconds. Requests authenticated with a project API key or as a privileged administrator allow `length` from 4 to 128 digits and `expire` from 60 to 31536000 seconds (1 year).

A user is limited to 10 active sessions at a time by default. [Learn more about session limits](https://appwrite.io/docs/authentication-security#limits).
