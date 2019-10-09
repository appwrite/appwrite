import "package:appwrite/service.dart";
import "package:appwrite/client.dart";
import 'package:dio/dio.dart';

class Account extends Service {
     
     Account(Client client): super(client);

     /// /docs/references/account/get.md
    Future<Response> get() async {
       String path = '/account';

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/account/delete.md
    Future<Response> delete() async {
       String path = '/account';

       Map<String, dynamic> params = {
       };

       return await this.client.call('delete', path: path, params: params);
    }
     /// /docs/references/account/update-email.md
    Future<Response> updateEmail({email, password}) async {
       String path = '/account/email';

       Map<String, dynamic> params = {
         'email': email,
         'password': password,
       };

       return await this.client.call('patch', path: path, params: params);
    }
     /// /docs/references/account/update-name.md
    Future<Response> updateName({name}) async {
       String path = '/account/name';

       Map<String, dynamic> params = {
         'name': name,
       };

       return await this.client.call('patch', path: path, params: params);
    }
     /// /docs/references/account/update-password.md
    Future<Response> updatePassword({password, oldPassword}) async {
       String path = '/account/password';

       Map<String, dynamic> params = {
         'password': password,
         'old-password': oldPassword,
       };

       return await this.client.call('patch', path: path, params: params);
    }
     /// /docs/references/account/get-prefs.md
    Future<Response> getPrefs() async {
       String path = '/account/prefs';

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/account/update-prefs.md
    Future<Response> updatePrefs({prefs}) async {
       String path = '/account/prefs';

       Map<String, dynamic> params = {
         'prefs': prefs,
       };

       return await this.client.call('patch', path: path, params: params);
    }
     /// /docs/references/account/get-security.md
    Future<Response> getSecurity() async {
       String path = '/account/security';

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/account/get-sessions.md
    Future<Response> getSessions() async {
       String path = '/account/sessions';

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
}