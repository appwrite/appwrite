package .services;



import okhttp3.Call;
import .Client;
import .enums.OrderType;

import java.io.File;
import java.util.List;
import java.util.HashMap;
import java.util.Map;

import static java.util.Map.entry;

public class Locale extends Service {
    public Locale(Client client){
        super(client);
    }

     /// Get User Locale
     /*
     * Get the current user location based on IP. Returns an object with user
     * country code, country name, continent name, continent code, ip address and
     * suggested currency. You can use the locale header to get the data in a
     * supported language.
     * 
     * ([IP Geolocation by DB-IP](https://db-ip.com))
     */
    public Call get() {
        final String path = "/locale";

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// List Continents
     /*
     * List of all continents. You can use the locale header to get the data in a
     * supported language.
     */
    public Call getContinents() {
        final String path = "/locale/continents";

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// List Countries
     /*
     * List of all countries. You can use the locale header to get the data in a
     * supported language.
     */
    public Call getCountries() {
        final String path = "/locale/countries";

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// List EU Countries
     /*
     * List of all countries that are currently members of the EU. You can use the
     * locale header to get the data in a supported language.
     */
    public Call getCountriesEU() {
        final String path = "/locale/countries/eu";

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// List Countries Phone Codes
     /*
     * List of all countries phone codes. You can use the locale header to get the
     * data in a supported language.
     */
    public Call getCountriesPhones() {
        final String path = "/locale/countries/phones";

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }

     /// List Currencies
     /*
     * List of all currencies, including currency symol, name, plural, and decimal
     * digits for all major and minor currencies. You can use the locale header to
     * get the data in a supported language.
     */
    public Call getCurrencies() {
        final String path = "/locale/currencies";

        final Map<String, Object> params = Map.ofEntries(
        );



        final Map<String, String> headers = Map.ofEntries(
            entry("content-type", "application/json")
        );

        return client.call("GET", path, headers, params);
    }
}