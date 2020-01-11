package appwrite

import (
	"strings"
)

// Teams service
type Teams struct {
	client *Client
}

// ListTeams get a list of all the current user teams. You can use the query
// params to filter your results. On admin mode, this endpoint will return a
// list of all of the project teams. [Learn more about different API
// modes](/docs/admin).
func (srv *Teams) ListTeams(Search string, Limit int, Offset int, OrderType string) (map[string]interface{}, error) {
	path := "/teams"

	params := map[string]interface{}{
		"search": Search,
		"limit": Limit,
		"offset": Offset,
		"orderType": OrderType,
	}

	return srv.client.Call("GET", path, nil, params)
}

// CreateTeam create a new team. The user who creates the team will
// automatically be assigned as the owner of the team. The team owner can
// invite new members, who will be able add new owners and update or delete
// the team from your project.
func (srv *Teams) CreateTeam(Name string, Roles []interface{}) (map[string]interface{}, error) {
	path := "/teams"

	params := map[string]interface{}{
		"name": Name,
		"roles": Roles,
	}

	return srv.client.Call("POST", path, nil, params)
}

// GetTeam get team by its unique ID. All team members have read access for
// this resource.
func (srv *Teams) GetTeam(TeamId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{teamId}", TeamId)
	path := r.Replace("/teams/{teamId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// UpdateTeam update team by its unique ID. Only team owners have write access
// for this resource.
func (srv *Teams) UpdateTeam(TeamId string, Name string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{teamId}", TeamId)
	path := r.Replace("/teams/{teamId}")

	params := map[string]interface{}{
		"name": Name,
	}

	return srv.client.Call("PUT", path, nil, params)
}

// DeleteTeam delete team by its unique ID. Only team owners have write access
// for this resource.
func (srv *Teams) DeleteTeam(TeamId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{teamId}", TeamId)
	path := r.Replace("/teams/{teamId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("DELETE", path, nil, params)
}

// GetTeamMembers get team members by the team unique ID. All team members
// have read access for this list of resources.
func (srv *Teams) GetTeamMembers(TeamId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{teamId}", TeamId)
	path := r.Replace("/teams/{teamId}/members")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// CreateTeamMembership use this endpoint to invite a new member to your team.
// An email with a link to join the team will be sent to the new member email
// address. If member doesn't exists in the project it will be automatically
// created.
// 
// Use the redirect parameter to redirect the user from the invitation email
// back to your app. When the user is redirected, use the
// /teams/{teamId}/memberships/{inviteId}/status endpoint to finally join the
// user to the team.
// 
// Please notice that in order to avoid a [Redirect
// Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
// the only valid redirect URL's are the once from domains you have set when
// added your platforms in the console interface.
func (srv *Teams) CreateTeamMembership(TeamId string, Email string, Roles []interface{}, Redirect string, Name string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{teamId}", TeamId)
	path := r.Replace("/teams/{teamId}/memberships")

	params := map[string]interface{}{
		"email": Email,
		"name": Name,
		"roles": Roles,
		"redirect": Redirect,
	}

	return srv.client.Call("POST", path, nil, params)
}

// DeleteTeamMembership this endpoint allows a user to leave a team or for a
// team owner to delete the membership of any other team member.
func (srv *Teams) DeleteTeamMembership(TeamId string, InviteId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{teamId}", TeamId, "{inviteId}", InviteId)
	path := r.Replace("/teams/{teamId}/memberships/{inviteId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("DELETE", path, nil, params)
}

// CreateTeamMembershipResend use this endpoint to resend your invitation
// email for a user to join a team.
func (srv *Teams) CreateTeamMembershipResend(TeamId string, InviteId string, Redirect string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{teamId}", TeamId, "{inviteId}", InviteId)
	path := r.Replace("/teams/{teamId}/memberships/{inviteId}/resend")

	params := map[string]interface{}{
		"redirect": Redirect,
	}

	return srv.client.Call("POST", path, nil, params)
}

// UpdateTeamMembershipStatus use this endpoint to let user accept an
// invitation to join a team after he is being redirect back to your app from
// the invitation email. Use the success and failure URL's to redirect users
// back to your application after the request completes.
// 
// Please notice that in order to avoid a [Redirect
// Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
// the only valid redirect URL's are the once from domains you have set when
// added your platforms in the console interface.
// 
// When not using the success or failure redirect arguments this endpoint will
// result with a 200 status code on success and with 401 status error on
// failure. This behavior was applied to help the web clients deal with
// browsers who don't allow to set 3rd party HTTP cookies needed for saving
// the account session token.
func (srv *Teams) UpdateTeamMembershipStatus(TeamId string, InviteId string, UserId string, Secret string, Success string, Failure string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{teamId}", TeamId, "{inviteId}", InviteId)
	path := r.Replace("/teams/{teamId}/memberships/{inviteId}/status")

	params := map[string]interface{}{
		"userId": UserId,
		"secret": Secret,
		"success": Success,
		"failure": Failure,
	}

	return srv.client.Call("PATCH", path, nil, params)
}
