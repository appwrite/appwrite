import "package:appwrite/service.dart";
import "package:appwrite/client.dart";
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
     /// Use this endpoint to allow a new user to register an account in your
     /// project. Use the success and failure URLs to redirect users back to your
     /// application after signup completes.
     /// 
     /// If registration completes successfully user will be sent with a
     /// confirmation email in order to confirm he is the owner of the account email
     /// address. Use the confirmation parameter to redirect the user from the
     /// confirmation email back to your app. When the user is redirected, use the
     /// /auth/confirm endpoint to complete the account confirmation.
     /// 
     /// Please note that in order to avoid a [Redirect
     /// Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     /// the only valid redirect URLs are the ones from domains you have set when
     /// adding your platforms in the console interface.
     /// 
     /// When accessing this route using Javascript from the browser, success and
     /// failure parameter URLs are required. Appwrite server will respond with a
     /// 301 redirect status code and will set the user session cookie. This
     /// behavior is enforced because modern browsers are limiting 3rd party cookies
     /// in XHR of fetch requests to protect user privacy.
    Future<Response> create({email, password, name = null}) async {
       String path = '/account';

       Map<String, dynamic> params = {
         'email': email,
         'password': password,
         'name': name,
       };

       return await this.client.call('post', path: path, params: params);
    }
     /// Delete a currently logged in user account. Behind the scene, the user
     /// record is not deleted but permanently blocked from any access. This is done
     /// to avoid deleted accounts being overtaken by new users with the same email
     /// address. Any user-related resources like documents or storage files should
     /// be deleted separately.
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
     /// Get currently logged in user list of latest security activity logs. Each
     /// log returns user IP address, location and date and time of log.
    Future<Response> getLogs() async {
       String path = '/account/logs';

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
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
     /// Sends the user an email with a temporary secret key for password reset.
     /// When the user clicks the confirmation link he is redirected back to your
     /// app password reset URL with the secret key and email address values
     /// attached to the URL query string. Use the query string params to submit a
     /// request to the /auth/password/reset endpoint to complete the process.
    Future<Response> createRecovery({email, url}) async {
       String path = '/account/recovery';

       Map<String, dynamic> params = {
         'email': email,
         'url': url,
       };

       return await this.client.call('post', path: path, params: params);
    }
     /// Use this endpoint to complete the user account password reset. Both the
     /// **userId** and **secret** arguments will be passed as query parameters to
     /// the redirect URL you have provided when sending your request to the
     /// /auth/recovery endpoint.
     /// 
     /// Please note that in order to avoid a [Redirect
     /// Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     /// the only valid redirect URLs are the ones from domains you have set when
     /// adding your platforms in the console interface.
    Future<Response> updateRecovery({userId, secret, passwordA, passwordB}) async {
       String path = '/account/recovery';

       Map<String, dynamic> params = {
         'userId': userId,
         'secret': secret,
         'password-a': passwordA,
         'password-b': passwordB,
       };

       return await this.client.call('put', path: path, params: params);
    }
     /// Get currently logged in user list of active sessions across different
     /// devices.
    Future<Response> getSessions() async {
       String path = '/account/sessions';

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// Allow the user to login into his account by providing a valid email and
     /// password combination. Use the success and failure arguments to provide a
     /// redirect URL's back to your app when login is completed. 
     /// 
     /// Please note that in order to avoid a [Redirect
     /// Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     /// the only valid redirect URLs are the ones from domains you have set when
     /// adding your platforms in the console interface.
     /// 
     /// When accessing this route using Javascript from the browser, success and
     /// failure parameter URLs are required. Appwrite server will respond with a
     /// 301 redirect status code and will set the user session cookie. This
     /// behavior is enforced because modern browsers are limiting 3rd party cookies
     /// in XHR of fetch requests to protect user privacy.
    Future<Response> createSession({email, password}) async {
       String path = '/account/sessions';

       Map<String, dynamic> params = {
         'email': email,
         'password': password,
       };

       return await this.client.call('post', path: path, params: params);
    }
     /// Delete all sessions from the user account and remove any sessions cookies
     /// from the end client.
    Future<Response> deleteSessions() async {
       String path = '/account/sessions';

       Map<String, dynamic> params = {
       };

       return await this.client.call('delete', path: path, params: params);
    }
     /// Use this endpoint to log out the currently logged in user from his account.
     /// When successful this endpoint will delete the user session and remove the
     /// session secret cookie from the user client.
    Future<Response> deleteCurrentSession() async {
       String path = '/account/sessions/current';

       Map<String, dynamic> params = {
       };

       return await this.client.call('delete', path: path, params: params);
    }
     /// Allow the user to login to his account using the OAuth provider of his
     /// choice. Each OAuth provider should be enabled from the Appwrite console
     /// first. Use the success and failure arguments to provide a redirect URL's
     /// back to your app when login is completed.
    Future<Response> createOAuthSession({provider, success, failure}) async {
       String path = '/account/sessions/oauth/{provider}'.replaceAll(RegExp('{provider}'), provider);

       Map<String, dynamic> params = {
         'success': success,
         'failure': failure,
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// Use this endpoint to log out the currently logged in user from all his
     /// account sessions across all his different devices. When using the option id
     /// argument, only the session unique ID provider will be deleted.
    Future<Response> deleteSession({id}) async {
       String path = '/account/sessions/{id}'.replaceAll(RegExp('{id}'), id);

       Map<String, dynamic> params = {
       };

       return await this.client.call('delete', path: path, params: params);
    }
     /// Use this endpoint to complete the user email verification process. Use both
     /// the **userId** and **secret** parameters that were attached to your app URL
     /// to verify the user email ownership. If confirmed this route will return a
     /// 200 status code.
    Future<Response> updateVerification({userId, secret, passwordB}) async {
       String path = '/account/verification';

       Map<String, dynamic> params = {
         'userId': userId,
         'secret': secret,
         'password-b': passwordB,
       };

       return await this.client.call('put', path: path, params: params);
    }
}