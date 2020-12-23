package .services;



import okhttp3.Call;
import .Client;
import .enums.OrderType;

import java.io.File;
import java.util.List;
import java.util.HashMap;
import java.util.Map;

import static java.util.Map.entry;

public class Avatars extends Service {
    public Avatars(Client client){
        super(client);
    }

     /// Get Browser Icon
     /*
     * You can use this endpoint to show different browser icons to your users.
     * The code argument receives the browser code as it appears in your user
     * /account/sessions endpoint. Use width, height and quality arguments to
     * change the output settings.
     */
    public Call getBrowser(String code, int width, int height, int quality) {
        final String path = "/avatars/browsers/{code}".replace("{code}", code);

        final Map<String, Object> params = Map.ofEntries(
            entry("width", width),
            entry("height", height),
            entry("quality", quality)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Get Credit Card Icon
     /*
     * Need to display your users with your billing method or their payment
     * methods? The credit card endpoint will return you the icon of the credit
     * card provider you need. Use width, height and quality arguments to change
     * the output settings.
     */
    public Call getCreditCard(String code, int width, int height, int quality) {
        final String path = "/avatars/credit-cards/{code}".replace("{code}", code);

        final Map<String, Object> params = Map.ofEntries(
            entry("width", width),
            entry("height", height),
            entry("quality", quality)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Get Favicon
     /*
     * Use this endpoint to fetch the favorite icon (AKA favicon) of a  any remote
     * website URL.
     */
    public Call getFavicon(String url) {
        final String path = "/avatars/favicon";

        final Map<String, Object> params = Map.ofEntries(
            entry("url", url)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Get Country Flag
     /*
     * You can use this endpoint to show different country flags icons to your
     * users. The code argument receives the 2 letter country code. Use width,
     * height and quality arguments to change the output settings.
     */
    public Call getFlag(String code, int width, int height, int quality) {
        final String path = "/avatars/flags/{code}".replace("{code}", code);

        final Map<String, Object> params = Map.ofEntries(
            entry("width", width),
            entry("height", height),
            entry("quality", quality)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Get Image from URL
     /*
     * Use this endpoint to fetch a remote image URL and crop it to any image size
     * you want. This endpoint is very useful if you need to crop and display
     * remote images in your app or in case you want to make sure a 3rd party
     * image is properly served using a TLS protocol.
     */
    public Call getImage(String url, int width, int height) {
        final String path = "/avatars/image";

        final Map<String, Object> params = Map.ofEntries(
            entry("url", url),
            entry("width", width),
            entry("height", height)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// Get QR Code
     /*
     * Converts a given plain text to a QR code image. You can use the query
     * parameters to change the size and style of the resulting image.
     */
    public Call getQR(String text, int size, int margin, int download) {
        final String path = "/avatars/qr";

        final Map<String, Object> params = Map.ofEntries(
            entry("text", text),
            entry("size", size),
            entry("margin", margin),
            entry("download", download)
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }
}