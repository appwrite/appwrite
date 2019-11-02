package appwrite

import (
	"strings"
)

// Auth service
type Auth struct {
	client *Client
}

// Login allow the user to login into his account by providing a valid email
// and password combination. Use the success and failure arguments to provide
// a redirect URL\'s back to your app when login is completed. 
// 
// Please notice that in order to avoid a [Redirect
// Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
// the only valid redirect URLs are the ones from domains you have set when
// adding your platforms in the console interface.
// 
// When accessing this route using Javascript from the browser, success and
// failure parameter URLs are required. Appwrite server will respond with a
// 301 redirect status code and will set the user session cookie. This
// behavior is enforced because modern browsers are limiting 3rd party cookies
// in XHR of fetch requests to protect user privacy.
func (srv *Auth) Login(Email string, Password string, Success string, Failure string) (map[string]interface{}, error) {
	path := "/auth/login"

	params := map[string]interface{}{
		"email": Email,
		"password": Password,
		"success": Success,
		"failure": Failure,
	}

	return srv.client.Call("POST", path, nil, params)
}

// Oauth
func (srv *Auth) Oauth(Provider string, Success string, Failure string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{provider}", Provider)
	path := r.Replace("/auth/login/oauth/{provider}")

	params := map[string]interface{}{
		"success": Success,
		"failure": Failure,
	}

	return srv.client.Call("GET", path, nil, params)
}

// Logout use this endpoint to log out the currently logged in user from his
// account. When successful this endpoint will delete the user session and
// remove the session secret cookie from the user client.
func (srv *Auth) Logout() (map[string]interface{}, error) {
	path := "/auth/logout"

	params := map[string]interface{}{
	}

	return srv.client.Call("DELETE", path, nil, params)
}

// LogoutBySession use this endpoint to log out the currently logged in user
// from all his account sessions across all his different devices. When using
// the option id argument, only the session unique ID provider will be
// deleted.
func (srv *Auth) LogoutBySession(Id string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{id}", Id)
	path := r.Replace("/auth/logout/{id}")

	params := map[string]interface{}{
	}

	return srv.client.Call("DELETE", path, nil, params)
}

// Recovery sends the user an email with a temporary secret token for password
// reset. When the user clicks the confirmation link he is redirected back to
// your app password reset redirect URL with a secret token and email address
// values attached to the URL query string. Use the query string params to
// submit a request to the /auth/password/reset endpoint to complete the
// process.
func (srv *Auth) Recovery(Email string, Reset string) (map[string]interface{}, error) {
	path := "/auth/recovery"

	params := map[string]interface{}{
		"email": Email,
		"reset": Reset,
	}

	return srv.client.Call("POST", path, nil, params)
}

// RecoveryReset use this endpoint to complete the user account password
// reset. Both the **userId** and **token** arguments will be passed as query
// parameters to the redirect URL you have provided when sending your request
// to the /auth/recovery endpoint.
// 
// Please notice that in order to avoid a [Redirect
// Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
// the only valid redirect URLs are the ones from domains you have set when
// adding your platforms in the console interface.
func (srv *Auth) RecoveryReset(UserId string, Token string, PasswordA string, PasswordB string) (map[string]interface{}, error) {
	path := "/auth/recovery/reset"

	params := map[string]interface{}{
		"userId": UserId,
		"token": Token,
		"password-a": PasswordA,
		"password-b": PasswordB,
	}

	return srv.client.Call("PUT", path, nil, params)
}

// Register use this endpoint to allow a new user to register an account in
// your project. Use the success and failure URLs to redirect users back to
// your application after signup completes.
// 
// If registration completes successfully user will be sent with a
// confirmation email in order to confirm he is the owner of the account email
// address. Use the confirmation parameter to redirect the user from the
// confirmation email back to your app. When the user is redirected, use the
// /auth/confirm endpoint to complete the account confirmation.
// 
// Please notice that in order to avoid a [Redirect
// Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
// the only valid redirect URLs are the ones from domains you have set when
// adding your platforms in the console interface.
// 
// When accessing this route using Javascript from the browser, success and
// failure parameter URLs are required. Appwrite server will respond with a
// 301 redirect status code and will set the user session cookie. This
// behavior is enforced because modern browsers are limiting 3rd party cookies
// in XHR of fetch requests to protect user privacy.
func (srv *Auth) Register(Email string, Password string, Confirm string, Success string, Failure string, Name string) (map[string]interface{}, error) {
	path := "/auth/register"

	params := map[string]interface{}{
		"email": Email,
		"password": Password,
		"confirm": Confirm,
		"success": Success,
		"failure": Failure,
		"name": Name,
	}

	return srv.client.Call("POST", path, nil, params)
}

// Confirm use this endpoint to complete the confirmation of the user account
// email address. Both the **userId** and **token** arguments will be passed
// as query parameters to the redirect URL you have provided when sending your
// request to the /auth/register endpoint.
func (srv *Auth) Confirm(UserId string, Token string) (map[string]interface{}, error) {
	path := "/auth/register/confirm"

	params := map[string]interface{}{
		"userId": UserId,
		"token": Token,
	}

	return srv.client.Call("POST", path, nil, params)
}

// ConfirmResend this endpoint allows the user to request your app to resend
// him his email confirmation message. The redirect arguments act the same way
// as in /auth/register endpoint.
// 
// Please notice that in order to avoid a [Redirect
// Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
// the only valid redirect URLs are the ones from domains you have set when
// adding your platforms in the console interface.
func (srv *Auth) ConfirmResend(Confirm string) (map[string]interface{}, error) {
	path := "/auth/register/confirm/resend"

	params := map[string]interface{}{
		"confirm": Confirm,
	}

	return srv.client.Call("POST", path, nil, params)
}
