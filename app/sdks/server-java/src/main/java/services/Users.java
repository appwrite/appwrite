package .services;



import okhttp3.Call;
import .Client;
import .enums.OrderType;

import java.io.File;
import java.util.List;
import java.util.HashMap;
import java.util.Map;

import static java.util.Map.entry;

public class Users extends Service {
    public Users(Client client){
        super(client);
    }

     /// List Users
     /*
     * Get a list of all the project users. You can use the query params to filter
     * your results.
     */
    public Call list(String search, int limit, int offset, OrderType orderType) {
        final String path = "/users";

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

     /// Create User
     /*
     * Create a new user.
     */
    public Call create(String email, String password, String name) {
        final String path = "/users";

        final Map<String, Object> params = Map.ofEntries(
            entry("email", email),
            entry("password", password),
            entry("name", name)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("POST", path, headers, params);
    }

     /// Get User
     /*
     * Get user by its unique ID.
     */
    public Call get(String userId) {
        final String path = "/users/{userId}".replace("{userId}", userId);

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Get User Logs
     /*
     * Get user activity logs list by its unique ID.
     */
    public Call getLogs(String userId) {
        final String path = "/users/{userId}/logs".replace("{userId}", userId);

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Get User Preferences
     /*
     * Get user preferences by its unique ID.
     */
    public Call getPrefs(String userId) {
        final String path = "/users/{userId}/prefs".replace("{userId}", userId);

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Update User Preferences
     /*
     * Update user preferences by its unique ID. You can pass only the specific
     * settings you wish to update.
     */
    public Call updatePrefs(String userId, Object prefs) {
        final String path = "/users/{userId}/prefs".replace("{userId}", userId);

        final Map<String, Object> params = Map.ofEntries(
            entry("prefs", prefs)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("PATCH", path, headers, params);
    }

     /// Get User Sessions
     /*
     * Get user sessions list by its unique ID.
     */
    public Call getSessions(String userId) {
        final String path = "/users/{userId}/sessions".replace("{userId}", userId);

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Delete User Sessions
     /*
     * Delete all user sessions by its unique ID.
     */
    public Call deleteSessions(String userId) {
        final String path = "/users/{userId}/sessions".replace("{userId}", userId);

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("DELETE", path, headers, params);
    }

     /// Delete User Session
     /*
     * Delete user sessions by its unique ID.
     */
    public Call deleteSession(String userId, String sessionId) {
        final String path = "/users/{userId}/sessions/{sessionId}".replace("{userId}", userId).replace("{sessionId}", sessionId);

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("DELETE", path, headers, params);
    }

     /// Update User Status
     /*
     * Update user status by its unique ID.
     */
    public Call updateStatus(String userId, String status) {
        final String path = "/users/{userId}/status".replace("{userId}", userId);

        final Map<String, Object> params = Map.ofEntries(
            entry("status", status)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("PATCH", path, headers, params);
    }
}