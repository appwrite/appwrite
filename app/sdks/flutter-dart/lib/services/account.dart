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
     /// Use this endpoint to allow a new user to register a new account in your
     /// project. After the user registration completes successfully, you can use
     /// the [/account/verfication](/docs/account#createVerification) route to start
     /// verifying the user email address. To allow your new user to login to his
     /// new account, you need to create a new [account
     /// session](/docs/account#createSession).
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
     /// Get currently logged in user preferences as a key-value object.
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
     /// request to the [PUT /account/recovery](/docs/account#updateRecovery)
     /// endpoint to complete the process.
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
     /// the redirect URL you have provided when sending your request to the [POST
     /// /account/recovery](/docs/account#createRecovery) endpoint.
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
     /// password combination. This route will create a new session for the user.
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
     /// Allow the user to login to his account using the OAuth2 provider of his
     /// choice. Each OAuth2 provider should be enabled from the Appwrite console
     /// first. Use the success and failure arguments to provide a redirect URL's
     /// back to your app when login is completed.
    Future<Response> createOAuth2Session({provider, success, failure}) async {
       String path = '/account/sessions/oauth2/{provider}'.replaceAll(RegExp('{provider}'), provider);

       Map<String, dynamic> params = {
         'success': success,
         'failure': failure,
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// Use this endpoint to log out the currently logged in user from all his
     /// account sessions across all his different devices. When using the option id
     /// argument, only the session unique ID provider will be deleted.
    Future<Response> deleteSession({sessionId}) async {
       String path = '/account/sessions/{sessionId}'.replaceAll(RegExp('{sessionId}'), sessionId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('delete', path: path, params: params);
    }
     /// Use this endpoint to send a verification message to your user email address
     /// to confirm they are the valid owners of that address. Both the **userId**
     /// and **secret** arguments will be passed as query parameters to the URL you
     /// have provider to be attached to the verification email. The provided URL
     /// should redirect the user back for your app and allow you to complete the
     /// verification process by verifying both the **userId** and **secret**
     /// parameters. Learn more about how to [complete the verification
     /// process](/docs/account#updateAccountVerification). 
     /// 
     /// Please note that in order to avoid a [Redirect
     /// Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     /// the only valid redirect URLs are the ones from domains you have set when
     /// adding your platforms in the console interface.
    Future<Response> createVerification({url}) async {
       String path = '/account/verification';

       Map<String, dynamic> params = {
         'url': url,
       };

       return await this.client.call('post', path: path, params: params);
    }
     /// Use this endpoint to complete the user email verification process. Use both
     /// the **userId** and **secret** parameters that were attached to your app URL
     /// to verify the user email ownership. If confirmed this route will return a
     /// 200 status code.
    Future<Response> updateVerification({userId, secret}) async {
       String path = '/account/verification';

       Map<String, dynamic> params = {
         'userId': userId,
         'secret': secret,
       };

       return await this.client.call('put', path: path, params: params);
    }
}