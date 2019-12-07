package appwrite

import (
)

// Account service
type Account struct {
	client *Client
}

// Get get currently logged in user data as JSON object.
func (srv *Account) Get() (map[string]interface{}, error) {
	path := "/account"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
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

// UpdateName update currently logged in user account name.
func (srv *Account) UpdateName(Name string) (map[string]interface{}, error) {
	path := "/account/name"

	params := map[string]interface{}{
		"name": Name,
	}

	return srv.client.Call("PATCH", path, nil, params)
}

// UpdatePassword update currently logged in user password. For validation,
// user is required to pass the password twice.
func (srv *Account) UpdatePassword(Password string, OldPassword string) (map[string]interface{}, error) {
	path := "/account/password"

	params := map[string]interface{}{
		"password": Password,
		"old-password": OldPassword,
	}

	return srv.client.Call("PATCH", path, nil, params)
}

// GetPrefs get currently logged in user preferences key-value object.
func (srv *Account) GetPrefs() (map[string]interface{}, error) {
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

// GetSecurity get currently logged in user list of latest security activity
// logs. Each log returns user IP address, location and date and time of log.
func (srv *Account) GetSecurity() (map[string]interface{}, error) {
	path := "/account/security"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// GetSessions get currently logged in user list of active sessions across
// different devices.
func (srv *Account) GetSessions() (map[string]interface{}, error) {
	path := "/account/sessions"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}
