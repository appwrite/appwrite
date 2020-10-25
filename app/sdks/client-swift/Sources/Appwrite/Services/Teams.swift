

class Teams: Service
{
    /**
     * List Teams
     *
     * Get a list of all the current user teams. You can use the query params to
     * filter your results. On admin mode, this endpoint will return a list of all
     * of the project teams. [Learn more about different API modes](/docs/admin).
     *
     * @param String _search
     * @param Int _limit
     * @param Int _offset
     * @param String _orderType
     * @throws Exception
     * @return array
     */

    func list(_search: String = "", _limit: Int = 25, _offset: Int = 0, _orderType: String = "ASC") -> Array<Any> {
        let path: String = "/teams"


                var params: [String: Any] = [:]
        
        params["search"] = _search
        params["limit"] = _limit
        params["offset"] = _offset
        params["orderType"] = _orderType

        return [self.client.call(method: Client.HTTPMethod.get.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)];
    }

    /**
     * Create Team
     *
     * Create a new team. The user who creates the team will automatically be
     * assigned as the owner of the team. The team owner can invite new members,
     * who will be able add new owners and update or delete the team from your
     * project.
     *
     * @param String _name
     * @param Array<Any> _roles
     * @throws Exception
     * @return array
     */

    func create(_name: String, _roles: Array<Any> = ["owner"]) -> Array<Any> {
        let path: String = "/teams"


                var params: [String: Any] = [:]
        
        params["name"] = _name
        params["roles"] = _roles

        return [self.client.call(method: Client.HTTPMethod.post.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)];
    }

    /**
     * Get Team
     *
     * Get team by its unique ID. All team members have read access for this
     * resource.
     *
     * @param String _teamId
     * @throws Exception
     * @return array
     */

    func get(_teamId: String) -> Array<Any> {
        var path: String = "/teams/{teamId}"

        path = path.replacingOccurrences(
          of: "{teamId}",
          with: _teamId
        )

                let params: [String: Any] = [:]
        

        return [self.client.call(method: Client.HTTPMethod.get.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)];
    }

    /**
     * Update Team
     *
     * Update team by its unique ID. Only team owners have write access for this
     * resource.
     *
     * @param String _teamId
     * @param String _name
     * @throws Exception
     * @return array
     */

    func update(_teamId: String, _name: String) -> Array<Any> {
        var path: String = "/teams/{teamId}"

        path = path.replacingOccurrences(
          of: "{teamId}",
          with: _teamId
        )

                var params: [String: Any] = [:]
        
        params["name"] = _name

        return [self.client.call(method: Client.HTTPMethod.put.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)];
    }

    /**
     * Delete Team
     *
     * Delete team by its unique ID. Only team owners have write access for this
     * resource.
     *
     * @param String _teamId
     * @throws Exception
     * @return array
     */

    func delete(_teamId: String) -> Array<Any> {
        var path: String = "/teams/{teamId}"

        path = path.replacingOccurrences(
          of: "{teamId}",
          with: _teamId
        )

                let params: [String: Any] = [:]
        

        return [self.client.call(method: Client.HTTPMethod.delete.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)];
    }

    /**
     * Get Team Memberships
     *
     * Get team members by the team unique ID. All team members have read access
     * for this list of resources.
     *
     * @param String _teamId
     * @param String _search
     * @param Int _limit
     * @param Int _offset
     * @param String _orderType
     * @throws Exception
     * @return array
     */

    func getMemberships(_teamId: String, _search: String = "", _limit: Int = 25, _offset: Int = 0, _orderType: String = "ASC") -> Array<Any> {
        var path: String = "/teams/{teamId}/memberships"

        path = path.replacingOccurrences(
          of: "{teamId}",
          with: _teamId
        )

                var params: [String: Any] = [:]
        
        params["search"] = _search
        params["limit"] = _limit
        params["offset"] = _offset
        params["orderType"] = _orderType

        return [self.client.call(method: Client.HTTPMethod.get.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)];
    }

    /**
     * Create Team Membership
     *
     * Use this endpoint to invite a new member to join your team. An email with a
     * link to join the team will be sent to the new member email address if the
     * member doesn't exist in the project it will be created automatically.
     * 
     * Use the 'URL' parameter to redirect the user from the invitation email back
     * to your app. When the user is redirected, use the [Update Team Membership
     * Status](/docs/client/teams#updateMembershipStatus) endpoint to allow the
     * user to accept the invitation to the team.
     * 
     * Please note that in order to avoid a [Redirect
     * Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     * the only valid redirect URL's are the once from domains you have set when
     * added your platforms in the console interface.
     *
     * @param String _teamId
     * @param String _email
     * @param Array<Any> _roles
     * @param String _url
     * @param String _name
     * @throws Exception
     * @return array
     */

    func createMembership(_teamId: String, _email: String, _roles: Array<Any>, _url: String, _name: String = "") -> Array<Any> {
        var path: String = "/teams/{teamId}/memberships"

        path = path.replacingOccurrences(
          of: "{teamId}",
          with: _teamId
        )

                var params: [String: Any] = [:]
        
        params["email"] = _email
        params["name"] = _name
        params["roles"] = _roles
        params["url"] = _url

        return [self.client.call(method: Client.HTTPMethod.post.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)];
    }

    /**
     * Delete Team Membership
     *
     * This endpoint allows a user to leave a team or for a team owner to delete
     * the membership of any other team member. You can also use this endpoint to
     * delete a user membership even if he didn't accept it.
     *
     * @param String _teamId
     * @param String _inviteId
     * @throws Exception
     * @return array
     */

    func deleteMembership(_teamId: String, _inviteId: String) -> Array<Any> {
        var path: String = "/teams/{teamId}/memberships/{inviteId}"

        path = path.replacingOccurrences(
          of: "{teamId}",
          with: _teamId
        )
        path = path.replacingOccurrences(
          of: "{inviteId}",
          with: _inviteId
        )

                let params: [String: Any] = [:]
        

        return [self.client.call(method: Client.HTTPMethod.delete.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)];
    }

    /**
     * Update Team Membership Status
     *
     * Use this endpoint to allow a user to accept an invitation to join a team
     * after he is being redirected back to your app from the invitation email he
     * was sent.
     *
     * @param String _teamId
     * @param String _inviteId
     * @param String _userId
     * @param String _secret
     * @throws Exception
     * @return array
     */

    func updateMembershipStatus(_teamId: String, _inviteId: String, _userId: String, _secret: String) -> Array<Any> {
        var path: String = "/teams/{teamId}/memberships/{inviteId}/status"

        path = path.replacingOccurrences(
          of: "{teamId}",
          with: _teamId
        )
        path = path.replacingOccurrences(
          of: "{inviteId}",
          with: _inviteId
        )

                var params: [String: Any] = [:]
        
        params["userId"] = _userId
        params["secret"] = _secret

        return [self.client.call(method: Client.HTTPMethod.patch.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)];
    }

}
