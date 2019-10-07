Use this endpoint to allow a new user to register an account in your project. Use the success and failure URL's to redirect users back to your application after signup completes.

If registration completes successfully user will be sent with a confirmation email in order to confirm he is the owner of the account email address. Use the confirmation parameter to redirect the user from the confirmation email back to your app. When the user is redirected, use the /auth/confirm endpoint to complete the account confirmation.

Please notice that in order to avoid a [Redirect Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md) the only valid redirect URL's are the once from domains you have set when added your platforms in the console interface.

When accessing this route using JavaScript from the browser, success and failure parameter URLs are required. Appwrite server will respond with a 301 redirect status code and will set the user session cookie. This behavior is enforced because modern browsers are limiting 3rd party cookies in XHR of fetch requests to protect user privacy.