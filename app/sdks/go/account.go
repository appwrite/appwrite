package appwrite

import (
	"strings"
)

// Account service
type Account struct {
	client *Client
}

// GetAccount get currently logged in user data as JSON object.
func (srv *Account) GetAccount() (map[string]interface{}, error) {
	path := "/account"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// CreateAccount use this endpoint to allow a new user to register an account
// in your project. Use the success and failure URLs to redirect users back to
// your application after signup completes.
// 
// If registration completes successfully user will be sent with a
// confirmation email in order to confirm he is the owner of the account email
// address. Use the confirmation parameter to redirect the user from the
// confirmation email back to your app. When the user is redirected, use the
// /auth/confirm endpoint to complete the account confirmation.
// 
// Please note that in order to avoid a [Redirect
// Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
// the only valid redirect URLs are the ones from domains you have set when
// adding your platforms in the console interface.
// 
// When accessing this route using Javascript from the browser, success and
// failure parameter URLs are required. Appwrite server will respond with a
// 301 redirect status code and will set the user session cookie. This
// behavior is enforced because modern browsers are limiting 3rd party cookies
// in XHR of fetch requests to protect user privacy.
func (srv *Account) CreateAccount(Email string, Password string, Name string) (map[string]interface{}, error) {
	path := "/account"

	params := map[string]interface{}{
		"email": Email,
		"password": Password,
		"name": Name,
	}

	return srv.client.Call("POST", path, nil, params)
}

// Delete delete a currently logged in user account. Behind the scene, the
// user record is not deleted but permanently blocked from any access. This is
// done to avoid deleted accounts being overtaken by new users with the same
// email address. Any user-related resources like documents or storage files
// should be deleted separately.
func (srv *Account) Delete() (map[string]interface{}, error) {
	path := "/account"

	params := map[string]interface{}{
	}

	return srv.client.Call("DELETE", path, nil, params)
}

// UpdateEmail update currently logged in user account email address. After
// changing user address, user confirmation status is being reset and a new
// confirmation mail is sent. For security measures, user password is required
// to complete this request.
func (srv *Account) UpdateEmail(Email string, Password string) (map[string]interface{}, error) {
	path := "/account/email"

	params := map[string]interface{}{
		"email": Email,
		"password": Password,
	}

	return srv.client.Call("PATCH", path, nil, params)
}

// GetAccountLogs get currently logged in user list of latest security
// activity logs. Each log returns user IP address, location and date and time
// of log.
func (srv *Account) GetAccountLogs() (map[string]interface{}, error) {
	path := "/account/logs"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// UpdateAccountName update currently logged in user account name.
func (srv *Account) UpdateAccountName(Name string) (map[string]interface{}, error) {
	path := "/account/name"

	params := map[string]interface{}{
		"name": Name,
	}

	return srv.client.Call("PATCH", path, nil, params)
}

// UpdateAccountPassword update currently logged in user password. For
// validation, user is required to pass the password twice.
func (srv *Account) UpdateAccountPassword(Password string, OldPassword string) (map[string]interface{}, error) {
	path := "/account/password"

	params := map[string]interface{}{
		"password": Password,
		"old-password": OldPassword,
	}

	return srv.client.Call("PATCH", path, nil, params)
}

// GetAccountPrefs get currently logged in user preferences key-value object.
func (srv *Account) GetAccountPrefs() (map[string]interface{}, error) {
	path := "/account/prefs"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// UpdatePrefs update currently logged in user account preferences. You can
// pass only the specific settings you wish to update.
func (srv *Account) UpdatePrefs(Prefs string) (map[string]interface{}, error) {
	path := "/account/prefs"

	params := map[string]interface{}{
		"prefs": Prefs,
	}

	return srv.client.Call("PATCH", path, nil, params)
}

// CreateAccountRecovery sends the user an email with a temporary secret token
// for password reset. When the user clicks the confirmation link he is
// redirected back to your app password reset redirect URL with a secret token
// and email address values attached to the URL query string. Use the query
// string params to submit a request to the /auth/password/reset endpoint to
// complete the process.
func (srv *Account) CreateAccountRecovery(Email string, Url string) (map[string]interface{}, error) {
	path := "/account/recovery"

	params := map[string]interface{}{
		"email": Email,
		"url": Url,
	}

	return srv.client.Call("POST", path, nil, params)
}

// UpdateAccountRecovery use this endpoint to complete the user account
// password reset. Both the **userId** and **token** arguments will be passed
// as query parameters to the redirect URL you have provided when sending your
// request to the /auth/recovery endpoint.
// 
// Please note that in order to avoid a [Redirect
// Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
// the only valid redirect URLs are the ones from domains you have set when
// adding your platforms in the console interface.
func (srv *Account) UpdateAccountRecovery(UserId string, Secret string, PasswordA string, PasswordB string) (map[string]interface{}, error) {
	path := "/account/recovery"

	params := map[string]interface{}{
		"userId": UserId,
		"secret": Secret,
		"password-a": PasswordA,
		"password-b": PasswordB,
	}

	return srv.client.Call("PUT", path, nil, params)
}

// GetAccountSessions get currently logged in user list of active sessions
// across different devices.
func (srv *Account) GetAccountSessions() (map[string]interface{}, error) {
	path := "/account/sessions"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// CreateAccountSession allow the user to login into his account by providing
// a valid email and password combination. Use the success and failure
// arguments to provide a redirect URL's back to your app when login is
// completed. 
// 
// Please note that in order to avoid a [Redirect
// Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
// the only valid redirect URLs are the ones from domains you have set when
// adding your platforms in the console interface.
// 
// When accessing this route using Javascript from the browser, success and
// failure parameter URLs are required. Appwrite server will respond with a
// 301 redirect status code and will set the user session cookie. This
// behavior is enforced because modern browsers are limiting 3rd party cookies
// in XHR of fetch requests to protect user privacy.
func (srv *Account) CreateAccountSession(Email string, Password string) (map[string]interface{}, error) {
	path := "/account/sessions"

	params := map[string]interface{}{
		"email": Email,
		"password": Password,
	}

	return srv.client.Call("POST", path, nil, params)
}

// DeleteAccountSessions delete all sessions from the user account and remove
// any sessions cookies from the end client.
func (srv *Account) DeleteAccountSessions() (map[string]interface{}, error) {
	path := "/account/sessions"

	params := map[string]interface{}{
	}

	return srv.client.Call("DELETE", path, nil, params)
}

// DeleteAccountCurrentSession use this endpoint to log out the currently
// logged in user from his account. When successful this endpoint will delete
// the user session and remove the session secret cookie from the user client.
func (srv *Account) DeleteAccountCurrentSession() (map[string]interface{}, error) {
	path := "/account/sessions/current"

	params := map[string]interface{}{
	}

	return srv.client.Call("DELETE", path, nil, params)
}

// CreateAccountSessionOAuth allow the user to login to his account using the
// OAuth provider of his choice. Each OAuth provider should be enabled from
// the Appwrite console first. Use the success and failure arguments to
// provide a redirect URL's back to your app when login is completed.
func (srv *Account) CreateAccountSessionOAuth(Provider string, Success string, Failure string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{provider}", Provider)
	path := r.Replace("/account/sessions/oauth/{provider}")

	params := map[string]interface{}{
		"success": Success,
		"failure": Failure,
	}

	return srv.client.Call("GET", path, nil, params)
}

// DeleteAccountSession use this endpoint to log out the currently logged in
// user from all his account sessions across all his different devices. When
// using the option id argument, only the session unique ID provider will be
// deleted.
func (srv *Account) DeleteAccountSession(Id string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{id}", Id)
	path := r.Replace("/account/sessions/{id}")

	params := map[string]interface{}{
	}

	return srv.client.Call("DELETE", path, nil, params)
}

// CreateAccountVerification use this endpoint to send a verification message
// to your user email address to confirm they are the valid owners of that
// address. Both the **userId** and **token** arguments will be passed as
// query parameters to the URL you have provider to be attached to the
// verification email. The provided URL should redirect the user back for your
// app and allow you to complete the verification process by verifying both
// the **userId** and **token** parameters. Learn more about how to [complete
// the verification process](/docs/account#updateAccountVerification). 
// 
// Please note that in order to avoid a [Redirect
// Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
// the only valid redirect URLs are the ones from domains you have set when
// adding your platforms in the console interface.
func (srv *Account) CreateAccountVerification(Url string) (map[string]interface{}, error) {
	path := "/account/verification"

	params := map[string]interface{}{
		"url": Url,
	}

	return srv.client.Call("POST", path, nil, params)
}

// UpdateAccountVerification use this endpoint to complete the user email
// verification process. Use both the **userId** and **token** parameters that
// were attached to your app URL to verify the user email ownership. If
// confirmed this route will return a 200 status code.
func (srv *Account) UpdateAccountVerification(UserId string, Secret string, PasswordB string) (map[string]interface{}, error) {
	path := "/account/verification"

	params := map[string]interface{}{
		"userId": UserId,
		"secret": Secret,
		"password-b": PasswordB,
	}

	return srv.client.Call("PUT", path, nil, params)
}
