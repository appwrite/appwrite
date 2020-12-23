package .services;



import okhttp3.Call;
import .Client;
import .enums.OrderType;

import java.io.File;
import java.util.List;
import java.util.HashMap;
import java.util.Map;

import static java.util.Map.entry;

public class Health extends Service {
    public Health(Client client){
        super(client);
    }

     /// Get HTTP
     /*
     * Check the Appwrite HTTP server is up and responsive.
     */
    public Call get() {
        final String path = "/health";

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Get Anti virus
     /*
     * Check the Appwrite Anti Virus server is up and connection is successful.
     */
    public Call getAntiVirus() {
        final String path = "/health/anti-virus";

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Get Cache
     /*
     * Check the Appwrite in-memory cache server is up and connection is
     * successful.
     */
    public Call getCache() {
        final String path = "/health/cache";

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Get DB
     /*
     * Check the Appwrite database server is up and connection is successful.
     */
    public Call getDB() {
        final String path = "/health/db";

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Get Certificate Queue
     /*
     * Get the number of certificates that are waiting to be issued against
     * [Letsencrypt](https://letsencrypt.org/) in the Appwrite internal queue
     * server.
     */
    public Call getQueueCertificates() {
        final String path = "/health/queue/certificates";

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Get Functions Queue
    public Call getQueueFunctions() {
        final String path = "/health/queue/functions";

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Get Logs Queue
     /*
     * Get the number of logs that are waiting to be processed in the Appwrite
     * internal queue server.
     */
    public Call getQueueLogs() {
        final String path = "/health/queue/logs";

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Get Tasks Queue
     /*
     * Get the number of tasks that are waiting to be processed in the Appwrite
     * internal queue server.
     */
    public Call getQueueTasks() {
        final String path = "/health/queue/tasks";

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Get Usage Queue
     /*
     * Get the number of usage stats that are waiting to be processed in the
     * Appwrite internal queue server.
     */
    public Call getQueueUsage() {
        final String path = "/health/queue/usage";

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Get Webhooks Queue
     /*
     * Get the number of webhooks that are waiting to be processed in the Appwrite
     * internal queue server.
     */
    public Call getQueueWebhooks() {
        final String path = "/health/queue/webhooks";

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Get Local Storage
     /*
     * Check the Appwrite local storage device is up and connection is successful.
     */
    public Call getStorageLocal() {
        final String path = "/health/storage/local";

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Get Time
     /*
     * Check the Appwrite server time is synced with Google remote NTP server. We
     * use this technology to smoothly handle leap seconds with no disruptive
     * events. The [Network Time
     * Protocol](https://en.wikipedia.org/wiki/Network_Time_Protocol) (NTP) is
     * used by hundreds of millions of computers and devices to synchronize their
     * clocks over the Internet. If your computer sets its own clock, it likely
     * uses NTP.
     */
    public Call getTime() {
        final String path = "/health/time";

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }
}