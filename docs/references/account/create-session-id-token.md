Allow the user to login to their account using an OpenID Connect ID token obtained natively from the OAuth2 provider, for example via Google Credential Manager on Android or Sign in with Apple on iOS. No browser or redirect is involved: the ID token is verified against the provider's published signing keys and a session is created in a single request.

The provider must be enabled in the Appwrite console, and the token's audience must match the provider's configured client ID or one of its native client IDs. For Sign in with Apple, register your app's bundle ID as a native client ID. For Google, the web client ID used by Credential Manager is usually the configured client ID; add your Android and iOS client IDs as native client IDs if your app requests tokens for them.

Pass the raw nonce used when requesting the ID token so it can be validated against the token's nonce claim. When signing in with Apple, hash the nonce with SHA-256 before passing it to the Apple SDK, and send the raw value here. Apple only returns the user's name on the first authorization, and never inside the ID token - capture it on the client and pass it via the name parameter.

If there is already an active session, the new session will be attached to the logged-in account. If there are no active sessions, the server will attempt to look for a user with the same email address as the verified email received from the provider and attach the new session to the existing user. If no matching user is found - the server will create a new user.

This flow does not return provider refresh tokens. If your app needs long-lived access to provider APIs, use the browser-based OAuth2 flow instead.

A user is limited to 10 active sessions at a time by default. [Learn more about session limits](https://appwrite.io/docs/authentication-security#limits).
