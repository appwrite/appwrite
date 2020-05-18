package .services;



import okhttp3.Call;
import .Client;
import .enums.OrderType;

import java.io.File;
import java.util.List;
import java.util.HashMap;
import java.util.Map;

import static java.util.Map.entry;

public class Database extends Service {
    public Database(Client client){
        super(client);
    }

     /// List Collections
     /*
     * Get a list of all the user collections. You can use the query params to
     * filter your results. On admin mode, this endpoint will return a list of all
     * of the project collections. [Learn more about different API
     * modes](/docs/admin).
     */
    public Call listCollections(String search, int limit, int offset, OrderType orderType) {
        final String path = "/database/collections";

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

     /// Create Collection
     /*
     * Create a new Collection.
     */
    public Call createCollection(String name, List read, List write, List rules) {
        final String path = "/database/collections";

        final Map<String, Object> params = Map.ofEntries(
            entry("name", name),
            entry("read", read),
            entry("write", write),
            entry("rules", rules)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("POST", path, headers, params);
    }

     /// Get Collection
     /*
     * Get collection by its unique ID. This endpoint response returns a JSON
     * object with the collection metadata.
     */
    public Call getCollection(String collectionId) {
        final String path = "/database/collections/{collectionId}".replace("{collectionId}", collectionId);

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Update Collection
     /*
     * Update collection by its unique ID.
     */
    public Call updateCollection(String collectionId, String name, List read, List write, List rules) {
        final String path = "/database/collections/{collectionId}".replace("{collectionId}", collectionId);

        final Map<String, Object> params = Map.ofEntries(
            entry("name", name),
            entry("read", read),
            entry("write", write),
            entry("rules", rules)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("PUT", path, headers, params);
    }

     /// Delete Collection
     /*
     * Delete a collection by its unique ID. Only users with write permissions
     * have access to delete this resource.
     */
    public Call deleteCollection(String collectionId) {
        final String path = "/database/collections/{collectionId}".replace("{collectionId}", collectionId);

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("DELETE", path, headers, params);
    }

     /// List Documents
     /*
     * Get a list of all the user documents. You can use the query params to
     * filter your results. On admin mode, this endpoint will return a list of all
     * of the project documents. [Learn more about different API
     * modes](/docs/admin).
     */
    public Call listDocuments(String collectionId, List filters, int offset, int limit, String orderField, OrderType orderType, String orderCast, String search, int first, int last) {
        final String path = "/database/collections/{collectionId}/documents".replace("{collectionId}", collectionId);

        final Map<String, Object> params = Map.ofEntries(
            entry("filters", filters),
            entry("offset", offset),
            entry("limit", limit),
            entry("orderField", orderField),
            entry("orderType", orderType.name()),
            entry("orderCast", orderCast),
            entry("search", search),
            entry("first", first),
            entry("last", last)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Create Document
     /*
     * Create a new Document.
     */
    public Call createDocument(String collectionId, Object data, List read, List write, String parentDocument, String parentProperty, String parentPropertyType) {
        final String path = "/database/collections/{collectionId}/documents".replace("{collectionId}", collectionId);

        final Map<String, Object> params = Map.ofEntries(
            entry("data", data),
            entry("read", read),
            entry("write", write),
            entry("parentDocument", parentDocument),
            entry("parentProperty", parentProperty),
            entry("parentPropertyType", parentPropertyType)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("POST", path, headers, params);
    }

     /// Get Document
     /*
     * Get document by its unique ID. This endpoint response returns a JSON object
     * with the document data.
     */
    public Call getDocument(String collectionId, String documentId) {
        final String path = "/database/collections/{collectionId}/documents/{documentId}".replace("{collectionId}", collectionId).replace("{documentId}", documentId);

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Update Document
    public Call updateDocument(String collectionId, String documentId, Object data, List read, List write) {
        final String path = "/database/collections/{collectionId}/documents/{documentId}".replace("{collectionId}", collectionId).replace("{documentId}", documentId);

        final Map<String, Object> params = Map.ofEntries(
            entry("data", data),
            entry("read", read),
            entry("write", write)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("PATCH", path, headers, params);
    }

     /// Delete Document
     /*
     * Delete document by its unique ID. This endpoint deletes only the parent
     * documents, his attributes and relations to other documents. Child documents
     * **will not** be deleted.
     */
    public Call deleteDocument(String collectionId, String documentId) {
        final String path = "/database/collections/{collectionId}/documents/{documentId}".replace("{collectionId}", collectionId).replace("{documentId}", documentId);

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("DELETE", path, headers, params);
    }

     /// Get Collection Logs
    public Call getCollectionLogs(String collectionId) {
        final String path = "/database/collections/{collectionId}/logs".replace("{collectionId}", collectionId);

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }
}