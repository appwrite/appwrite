package .services;



import okhttp3.Call;
import .Client;
import .enums.OrderType;

import java.io.File;
import java.util.List;
import java.util.HashMap;
import java.util.Map;

import static java.util.Map.entry;

public class Functions extends Service {
    public Functions(Client client){
        super(client);
    }

     /// List Functions
    public Call list(String search, int limit, int offset, OrderType orderType) {
        final String path = "/functions";

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

     /// Create Function
    public Call create(String name, Object vars, String trigger, List events, String schedule, int timeout) {
        final String path = "/functions";

        final Map<String, Object> params = Map.ofEntries(
            entry("name", name),
            entry("vars", vars),
            entry("trigger", trigger),
            entry("events", events),
            entry("schedule", schedule),
            entry("timeout", timeout)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("POST", path, headers, params);
    }

     /// Get Function
    public Call get(String functionId) {
        final String path = "/functions/{functionId}".replace("{functionId}", functionId);

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Update Function
    public Call update(String functionId, String name, Object vars, String trigger, List events, String schedule, int timeout) {
        final String path = "/functions/{functionId}".replace("{functionId}", functionId);

        final Map<String, Object> params = Map.ofEntries(
            entry("name", name),
            entry("vars", vars),
            entry("trigger", trigger),
            entry("events", events),
            entry("schedule", schedule),
            entry("timeout", timeout)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("PUT", path, headers, params);
    }

     /// Delete Function
    public Call delete(String functionId) {
        final String path = "/functions/{functionId}".replace("{functionId}", functionId);

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("DELETE", path, headers, params);
    }

     /// List Executions
    public Call listExecutions(String functionId, String search, int limit, int offset, OrderType orderType) {
        final String path = "/functions/{functionId}/executions".replace("{functionId}", functionId);

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

     /// Create Execution
    public Call createExecution(String functionId, int async) {
        final String path = "/functions/{functionId}/executions".replace("{functionId}", functionId);

        final Map<String, Object> params = Map.ofEntries(
            entry("async", async)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("POST", path, headers, params);
    }

     /// Get Execution
    public Call getExecution(String functionId, String executionId) {
        final String path = "/functions/{functionId}/executions/{executionId}".replace("{functionId}", functionId).replace("{executionId}", executionId);

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Update Function Tag
    public Call updateTag(String functionId, String tag) {
        final String path = "/functions/{functionId}/tag".replace("{functionId}", functionId);

        final Map<String, Object> params = Map.ofEntries(
            entry("tag", tag)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("PATCH", path, headers, params);
    }

     /// List Tags
    public Call listTags(String functionId, String search, int limit, int offset, OrderType orderType) {
        final String path = "/functions/{functionId}/tags".replace("{functionId}", functionId);

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

     /// Create Tag
    public Call createTag(String functionId, String env, String command, String code) {
        final String path = "/functions/{functionId}/tags".replace("{functionId}", functionId);

        final Map<String, Object> params = Map.ofEntries(
            entry("env", env),
            entry("command", command),
            entry("code", code)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("POST", path, headers, params);
    }

     /// Get Tag
    public Call getTag(String functionId, String tagId) {
        final String path = "/functions/{functionId}/tags/{tagId}".replace("{functionId}", functionId).replace("{tagId}", tagId);

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Delete Tag
    public Call deleteTag(String functionId, String tagId) {
        final String path = "/functions/{functionId}/tags/{tagId}".replace("{functionId}", functionId).replace("{tagId}", tagId);

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("DELETE", path, headers, params);
    }
}