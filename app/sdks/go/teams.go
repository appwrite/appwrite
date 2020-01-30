package appwrite

import (
	"strings"
)

// Teams service
type Teams struct {
	client Client
}

func NewTeams(clt Client) Teams {  
    service := Teams{
		client: clt,
	}

    return service
}

// List get a list of all the current user teams. You can use the query params
// to filter your results. On admin mode, this endpoint will return a list of
// all of the project teams. [Learn more about different API
// modes](/docs/admin).
func (srv *Teams) List(Search string, Limit int, Offset int, OrderType string) (map[string]interface{}, error) {
	path := "/teams"

	params := map[string]interface{}{
		"search": Search,
		"limit": Limit,
		"offset": Offset,
		"orderType": OrderType,
	}

	return srv.client.Call("GET", path, nil, params)
}

// Create create a new team. The user who creates the team will automatically
// be assigned as the owner of the team. The team owner can invite new
// members, who will be able add new owners and update or delete the team from
// your project.
func (srv *Teams) Create(Name string, Roles []interface{}) (map[string]interface{}, error) {
	path := "/teams"

	params := map[string]interface{}{
		"name": Name,
		"roles": Roles,
	}

	return srv.client.Call("POST", path, nil, params)
}

// Get get team by its unique ID. All team members have read access for this
// resource.
func (srv *Teams) Get(TeamId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{teamId}", TeamId)
	path := r.Replace("/teams/{teamId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// Update update team by its unique ID. Only team owners have write access for
// this resource.
func (srv *Teams) Update(TeamId string, Name string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{teamId}", TeamId)
	path := r.Replace("/teams/{teamId}")

	params := map[string]interface{}{
		"name": Name,
	}

	return srv.client.Call("PUT", path, nil, params)
}

// Delete delete team by its unique ID. Only team owners have write access for
// this resource.
func (srv *Teams) Delete(TeamId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{teamId}", TeamId)
	path := r.Replace("/teams/{teamId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("DELETE", path, nil, params)
}

// GetMemberships get team members by the team unique ID. All team members
// have read access for this list of resources.
func (srv *Teams) GetMemberships(TeamId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{teamId}", TeamId)
	path := r.Replace("/teams/{teamId}/memberships")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// CreateMembership use this endpoint to invite a new member to your team. An
// email with a link to join the team will be sent to the new member email
// address. If member doesn't exists in the project it will be automatically
// created.
// 
// Use the 'url' parameter to redirect the user from the invitation email back
// to your app. When the user is redirected, use the [Update Team Membership
// Status](/docs/teams#updateTeamMembershipStatus) endpoint to finally join
// the user to the team.
// 
// Please note that in order to avoid a [Redirect
// Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
// the only valid redirect URL's are the once from domains you have set when
// added your platforms in the console interface.
func (srv *Teams) CreateMembership(TeamId string, Email string, Roles []interface{}, Url string, Name string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{teamId}", TeamId)
	path := r.Replace("/teams/{teamId}/memberships")

	params := map[string]interface{}{
		"email": Email,
		"name": Name,
		"roles": Roles,
		"url": Url,
	}

	return srv.client.Call("POST", path, nil, params)
}

// DeleteMembership this endpoint allows a user to leave a team or for a team
// owner to delete the membership of any other team member. You can also use
// this endpoint to delete a user membership even if he didn't accept it.
func (srv *Teams) DeleteMembership(TeamId string, InviteId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{teamId}", TeamId, "{inviteId}", InviteId)
	path := r.Replace("/teams/{teamId}/memberships/{inviteId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("DELETE", path, nil, params)
}
