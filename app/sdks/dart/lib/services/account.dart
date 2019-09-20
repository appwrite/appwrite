import "package:dart_appwrite/service.dart";
import "package:dart_appwrite/client.dart";
import 'package:dio/dio.dart';

class Account extends Service {
     
     Account(Client client): super(client);

     /// Get currently logged in user data as JSON object.
    Future<Response> get() async {
       String path = '/account';

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// Delete currently logged in user account.
    Future<Response> delete() async {
       String path = '/account';

       Map<String, dynamic> params = {
       };

       return await this.client.call('delete', path: path, params: params);
    }
     /// Update currently logged in user account email address. After changing user
     /// address, user confirmation status is being reset and a new confirmation
     /// mail is sent. For security measures, user password is required to complete
     /// this request.
    Future<Response> updateEmail({email, password}) async {
       String path = '/account/email';

       Map<String, dynamic> params = {
         'email': email,
         'password': password,
       };

       return await this.client.call('patch', path: path, params: params);
    }
     /// Update currently logged in user account name.
    Future<Response> updateName({name}) async {
       String path = '/account/name';

       Map<String, dynamic> params = {
         'name': name,
       };

       return await this.client.call('patch', path: path, params: params);
    }
     /// Update currently logged in user password. For validation, user is required
     /// to pass the password twice.
    Future<Response> updatePassword({password, oldPassword}) async {
       String path = '/account/password';

       Map<String, dynamic> params = {
         'password': password,
         'old-password': oldPassword,
       };

       return await this.client.call('patch', path: path, params: params);
    }
     /// Get currently logged in user preferences key-value object.
    Future<Response> getPrefs() async {
       String path = '/account/prefs';

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// Update currently logged in user account preferences. You can pass only the
     /// specific settings you wish to update.
    Future<Response> updatePrefs({prefs}) async {
       String path = '/account/prefs';

       Map<String, dynamic> params = {
         'prefs': prefs,
       };

       return await this.client.call('patch', path: path, params: params);
    }
     /// Get currently logged in user list of latest security activity logs. Each
     /// log returns user IP address, location and date and time of log.
    Future<Response> getSecurity() async {
       String path = '/account/security';

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// Get currently logged in user list of active sessions across different
     /// devices.
    Future<Response> getSessions() async {
       String path = '/account/sessions';

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
}