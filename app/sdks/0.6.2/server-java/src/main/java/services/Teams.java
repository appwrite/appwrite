package .services;



import okhttp3.Call;
import .Client;
import .enums.OrderType;

import java.io.File;
import java.util.List;
import java.util.HashMap;
import java.util.Map;

import static java.util.Map.entry;

public class Teams extends Service {
    public Teams(Client client){
        super(client);
    }

     /// List Teams
     /*
     * Get a list of all the current user teams. You can use the query params to
     * filter your results. On admin mode, this endpoint will return a list of all
     * of the project teams. [Learn more about different API modes](/docs/admin).
     */
    public Call list(String search, int limit, int offset, OrderType orderType) {
        final String path = "/teams";

        final Map<String, Object> params = Map.ofEntries(
            entry("search", search),
            entry("limit", limit),
            entry("offset", offset),
            entry("orderType", orderType.name())
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Create Team
     /*
     * Create a new team. The user who creates the team will automatically be
     * assigned as the owner of the team. The team owner can invite new members,
     * who will be able add new owners and update or delete the team from your
     * project.
     */
    public Call create(String name, List roles) {
        final String path = "/teams";

        final Map<String, Object> params = Map.ofEntries(
            entry("name", name),
            entry("roles", roles)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("POST", path, headers, params);
    }

     /// Get Team
     /*
     * Get team by its unique ID. All team members have read access for this
     * resource.
     */
    public Call get(String teamId) {
        final String path = "/teams/{teamId}".replace("{teamId}", teamId);

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Update Team
     /*
     * Update team by its unique ID. Only team owners have write access for this
     * resource.
     */
    public Call update(String teamId, String name) {
        final String path = "/teams/{teamId}".replace("{teamId}", teamId);

        final Map<String, Object> params = Map.ofEntries(
            entry("name", name)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("PUT", path, headers, params);
    }

     /// Delete Team
     /*
     * Delete team by its unique ID. Only team owners have write access for this
     * resource.
     */
    public Call delete(String teamId) {
        final String path = "/teams/{teamId}".replace("{teamId}", teamId);

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("DELETE", path, headers, params);
    }

     /// Get Team Memberships
     /*
     * Get team members by the team unique ID. All team members have read access
     * for this list of resources.
     */
    public Call getMemberships(String teamId) {
        final String path = "/teams/{teamId}/memberships".replace("{teamId}", teamId);

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Create Team Membership
     /*
     * Use this endpoint to invite a new member to join your team. An email with a
     * link to join the team will be sent to the new member email address if the
     * member doesn't exist in the project it will be created automatically.
     * 
     * Use the 'URL' parameter to redirect the user from the invitation email back
     * to your app. When the user is redirected, use the [Update Team Membership
     * Status](/docs/teams#updateMembershipStatus) endpoint to allow the user to
     * accept the invitation to the team.
     * 
     * Please note that in order to avoid a [Redirect
     * Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     * the only valid redirect URL's are the once from domains you have set when
     * added your platforms in the console interface.
     */
    public Call createMembership(String teamId, String email, List roles, String url, String name) {
        final String path = "/teams/{teamId}/memberships".replace("{teamId}", teamId);

        final Map<String, Object> params = Map.ofEntries(
            entry("email", email),
            entry("name", name),
            entry("roles", roles),
            entry("url", url)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("POST", path, headers, params);
    }

     /// Delete Team Membership
     /*
     * This endpoint allows a user to leave a team or for a team owner to delete
     * the membership of any other team member. You can also use this endpoint to
     * delete a user membership even if he didn't accept it.
     */
    public Call deleteMembership(String teamId, String inviteId) {
        final String path = "/teams/{teamId}/memberships/{inviteId}".replace("{teamId}", teamId).replace("{inviteId}", inviteId);

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("DELETE", path, headers, params);
    }
}