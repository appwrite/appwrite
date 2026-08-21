Allow the user to login into their account using the credentials held by your project's LDAP directory. Appwrite verifies the credentials by binding to the directory and never stores the password. This route will create a new session for the user.

When the credentials are valid and no matching account exists yet, one is created from the directory entry. Which entries are eligible can be restricted to a group or filter in your project's LDAP settings.

A user is limited to 10 active sessions at a time by default. [Learn more about session limits](https://appwrite.io/docs/authentication-security#limits).