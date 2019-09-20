import "package:dart_appwrite/service.dart";
import "package:dart_appwrite/client.dart";
import 'package:dio/dio.dart';

class Locale extends Service {
     
     Locale(Client client): super(client);

     /// Get the current user location based on IP. Returns an object with user
     /// country code, country name, continent name, continent code, ip address and
     /// suggested currency. You can use the locale header to get the data in
     /// supported language.
    Future<Response> getLocale() async {
       String path = '/locale';

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// List of all countries. You can use the locale header to get the data in
     /// supported language.
    Future<Response> getCountries() async {
       String path = '/locale/countries';

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// List of all countries that are currently members of the EU. You can use the
     /// locale header to get the data in supported language. UK brexit date is
     /// currently set to 2019-10-31 and will be updated if and when needed.
    Future<Response> getCountriesEU() async {
       String path = '/locale/countries/eu';

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// List of all countries phone codes. You can use the locale header to get the
     /// data in supported language.
    Future<Response> getCountriesPhones() async {
       String path = '/locale/countries/phones';

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// List of all currencies, including currency symol, name, plural, and decimal
     /// digits for all major and minor currencies. You can use the locale header to
     /// get the data in supported language.
    Future<Response> getCurrencies() async {
       String path = '/locale/currencies';

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
}