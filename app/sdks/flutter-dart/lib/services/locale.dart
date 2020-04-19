

import 'package:dio/dio.dart';
import 'package:meta/meta.dart';

import "../client.dart";
import '../enums.dart';
import "../service.dart";

class Locale extends Service {
    Locale(Client client): super(client);

     /// Get User Locale
     ///
     /// Get the current user location based on IP. Returns an object with user
     /// country code, country name, continent name, continent code, ip address and
     /// suggested currency. You can use the locale header to get the data in a
     /// supported language.
     /// 
     /// ([IP Geolocation by DB-IP](https://db-ip.com))
     ///
    Future<Response> get() {
        final String path = '/locale';

        final Map<String, dynamic> params = {
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.get, path: path, params: params, headers: headers);
    }

     /// List Continents
     ///
     /// List of all continents. You can use the locale header to get the data in a
     /// supported language.
     ///
    Future<Response> getContinents() {
        final String path = '/locale/continents';

        final Map<String, dynamic> params = {
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.get, path: path, params: params, headers: headers);
    }

     /// List Countries
     ///
     /// List of all countries. You can use the locale header to get the data in a
     /// supported language.
     ///
    Future<Response> getCountries() {
        final String path = '/locale/countries';

        final Map<String, dynamic> params = {
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.get, path: path, params: params, headers: headers);
    }

     /// List EU Countries
     ///
     /// List of all countries that are currently members of the EU. You can use the
     /// locale header to get the data in a supported language.
     ///
    Future<Response> getCountriesEU() {
        final String path = '/locale/countries/eu';

        final Map<String, dynamic> params = {
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.get, path: path, params: params, headers: headers);
    }

     /// List Countries Phone Codes
     ///
     /// List of all countries phone codes. You can use the locale header to get the
     /// data in a supported language.
     ///
    Future<Response> getCountriesPhones() {
        final String path = '/locale/countries/phones';

        final Map<String, dynamic> params = {
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.get, path: path, params: params, headers: headers);
    }

     /// List Currencies
     ///
     /// List of all currencies, including currency symol, name, plural, and decimal
     /// digits for all major and minor currencies. You can use the locale header to
     /// get the data in a supported language.
     ///
    Future<Response> getCurrencies() {
        final String path = '/locale/currencies';

        final Map<String, dynamic> params = {
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.get, path: path, params: params, headers: headers);
    }
}