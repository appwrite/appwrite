package .services;



import okhttp3.Call;
import okhttp3.HttpUrl;
import .Client;
import .enums.OrderType;

import java.io.File;
import java.util.List;
import java.util.HashMap;
import java.util.Map;

import static java.util.Map.entry;

public class Storage extends Service {
    public Storage(Client client){
        super(client);
    }

     /// List Files
     /*
     * Get a list of all the user files. You can use the query params to filter
     * your results. On admin mode, this endpoint will return a list of all of the
     * project files. [Learn more about different API modes](/docs/admin).
     */
    public Call listFiles(String search, int limit, int offset, OrderType orderType) {
        final String path = "/storage/files";

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

     /// Create File
     /*
     * Create a new file. The user who creates the file will automatically be
     * assigned to read and write access unless he has passed custom values for
     * read and write arguments.
     */
    public Call createFile(File file, List read, List write) {
        final String path = "/storage/files";

        final Map<String, Object> params = Map.ofEntries(
            entry("file", file),
            entry("read", read),
            entry("write", write)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "multipart/form-data")
        );

        return client.call("POST", path, headers, params);
    }

     /// Get File
     /*
     * Get file by its unique ID. This endpoint response returns a JSON object
     * with the file metadata.
     */
    public Call getFile(String fileId) {
        final String path = "/storage/files/{fileId}".replace("{fileId}", fileId);

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Update File
     /*
     * Update file by its unique ID. Only users with write permissions have access
     * to update this resource.
     */
    public Call updateFile(String fileId, List read, List write) {
        final String path = "/storage/files/{fileId}".replace("{fileId}", fileId);

        final Map<String, Object> params = Map.ofEntries(
            entry("read", read),
            entry("write", write)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("PUT", path, headers, params);
    }

     /// Delete File
     /*
     * Delete a file by its unique ID. Only users with write permissions have
     * access to delete this resource.
     */
    public Call deleteFile(String fileId) {
        final String path = "/storage/files/{fileId}".replace("{fileId}", fileId);

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("DELETE", path, headers, params);
    }

     /// Get File for Download
     /*
     * Get file content by its unique ID. The endpoint response return with a
     * 'Content-Disposition: attachment' header that tells the browser to start
     * downloading the file to user downloads directory.
     */
    public String getFileDownload(String fileId) {
        final String path = "/storage/files/{fileId}/download".replace("{fileId}", fileId);

        final Map<String, Object> params = Map.ofEntries(
            entry("project", client.getConfig().get("project")),
            entry("key", client.getConfig().get("key"))
        );



        HttpUrl.Builder httpBuilder = new HttpUrl.Builder().build().newBuilder(client.getEndPoint() + path);
        params.forEach((k, v) -> httpBuilder.addQueryParameter(k, v.toString()));

        return httpBuilder.build().toString();
    }

     /// Get File Preview
     /*
     * Get a file preview image. Currently, this method supports preview for image
     * files (jpg, png, and gif), other supported formats, like pdf, docs, slides,
     * and spreadsheets, will return the file icon image. You can also pass query
     * string arguments for cutting and resizing your preview image.
     */
    public String getFilePreview(String fileId, int width, int height, int quality, String background, String output) {
        final String path = "/storage/files/{fileId}/preview".replace("{fileId}", fileId);

        final Map<String, Object> params = Map.ofEntries(
            entry("width", width),
            entry("height", height),
            entry("quality", quality),
            entry("background", background),
            entry("output", output),
            entry("project", client.getConfig().get("project")),
            entry("key", client.getConfig().get("key"))
        );



        HttpUrl.Builder httpBuilder = new HttpUrl.Builder().build().newBuilder(client.getEndPoint() + path);
        params.forEach((k, v) -> httpBuilder.addQueryParameter(k, v.toString()));

        return httpBuilder.build().toString();
    }

     /// Get File for View
     /*
     * Get file content by its unique ID. This endpoint is similar to the download
     * method but returns with no  'Content-Disposition: attachment' header.
     */
    public String getFileView(String fileId, String as) {
        final String path = "/storage/files/{fileId}/view".replace("{fileId}", fileId);

        final Map<String, Object> params = Map.ofEntries(
            entry("as", as),
            entry("project", client.getConfig().get("project")),
            entry("key", client.getConfig().get("key"))
        );



        HttpUrl.Builder httpBuilder = new HttpUrl.Builder().build().newBuilder(client.getEndPoint() + path);
        params.forEach((k, v) -> httpBuilder.addQueryParameter(k, v.toString()));

        return httpBuilder.build().toString();
    }
}