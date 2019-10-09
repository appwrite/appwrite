import "package:appwrite/service.dart";
import "package:appwrite/client.dart";
import 'package:dio/dio.dart';

class Locale extends Service {
     
     Locale(Client client): super(client);

     /// /docs/references/locale/get-locale.md
    Future<Response> getLocale() async {
       String path = '/locale';

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/locale/get-countires.md
    Future<Response> getCountries() async {
       String path = '/locale/countries';

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/locale/get-countries-eu.md
    Future<Response> getCountriesEU() async {
       String path = '/locale/countries/eu';

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/locale/get-countries-phones.md
    Future<Response> getCountriesPhones() async {
       String path = '/locale/countries/phones';

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/locale/get-currencies.md
    Future<Response> getCurrencies() async {
       String path = '/locale/currencies';

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
}