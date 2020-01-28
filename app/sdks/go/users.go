package appwrite

import (
	"strings"
)

// Users service
type Users struct {
	client Client
}

func NewUsers(clt *Client) *Users {  
    service := Users{
		client: clt,
	}

    return service
}

// ListUsers get a list of all the project users. You can use the query params
// to filter your results.
func (srv *Users) ListUsers(Search string, Limit int, Offset int, OrderType string) (map[string]interface{}, error) {
	path := "/users"

	params := map[string]interface{}{
		"search": Search,
		"limit": Limit,
		"offset": Offset,
		"orderType": OrderType,
	}

	return srv.client.Call("GET", path, nil, params)
}

// CreateUser create a new user.
func (srv *Users) CreateUser(Email string, Password string, Name string) (map[string]interface{}, error) {
	path := "/users"

	params := map[string]interface{}{
		"email": Email,
		"password": Password,
		"name": Name,
	}

	return srv.client.Call("POST", path, nil, params)
}

// GetUser get user by its unique ID.
func (srv *Users) GetUser(UserId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{userId}", UserId)
	path := r.Replace("/users/{userId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// GetUserLogs get user activity logs list by its unique ID.
func (srv *Users) GetUserLogs(UserId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{userId}", UserId)
	path := r.Replace("/users/{userId}/logs")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// GetUserPrefs get user preferences by its unique ID.
func (srv *Users) GetUserPrefs(UserId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{userId}", UserId)
	path := r.Replace("/users/{userId}/prefs")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// UpdateUserPrefs update user preferences by its unique ID. You can pass only
// the specific settings you wish to update.
func (srv *Users) UpdateUserPrefs(UserId string, Prefs string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{userId}", UserId)
	path := r.Replace("/users/{userId}/prefs")

	params := map[string]interface{}{
		"prefs": Prefs,
	}

	return srv.client.Call("PATCH", path, nil, params)
}

// GetUserSessions get user sessions list by its unique ID.
func (srv *Users) GetUserSessions(UserId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{userId}", UserId)
	path := r.Replace("/users/{userId}/sessions")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// DeleteUserSessions delete all user sessions by its unique ID.
func (srv *Users) DeleteUserSessions(UserId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{userId}", UserId)
	path := r.Replace("/users/{userId}/sessions")

	params := map[string]interface{}{
	}

	return srv.client.Call("DELETE", path, nil, params)
}

// DeleteUserSession delete user sessions by its unique ID.
func (srv *Users) DeleteUserSession(UserId string, SessionId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{userId}", UserId)
	path := r.Replace("/users/{userId}/sessions/:session")

	params := map[string]interface{}{
		"sessionId": SessionId,
	}

	return srv.client.Call("DELETE", path, nil, params)
}

// UpdateUserStatus update user status by its unique ID.
func (srv *Users) UpdateUserStatus(UserId string, Status string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{userId}", UserId)
	path := r.Replace("/users/{userId}/status")

	params := map[string]interface{}{
		"status": Status,
	}

	return srv.client.Call("PATCH", path, nil, params)
}
