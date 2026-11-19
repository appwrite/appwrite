Allow the user to login to their account using the OAuth2 provider of their choice. Each OAuth2 provider should be enabled from the Appwrite console first. Use the success and failure arguments to provide a redirect URL's back to your app when login is completed.

If there is already an active session, the OAuth2 identity is linked to the logged-in account and the new session replaces the current one. This default exists only for backwards compatibility: a sign-in page should pass `link` as false, which signs in the same way as when there is no active session. With `link` set to false and an active session, the new session joins the account switching device, or the sign-in fails with `user_session_already_exists` when there is no device.

If there are no active sessions, or `link` is false, the server will attempt to look for a user with the same email address as the email received from the OAuth2 provider and attach the new session to the existing user. If no matching user is found - the server will create a new user.

A user is limited to 10 active sessions at a time by default. [Learn more about session limits](https://appwrite.io/docs/authentication-security#limits).
