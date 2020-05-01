
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:meta/meta.dart';
import 'package:flutter_web_auth/flutter_web_auth.dart';

import "../client.dart";
import '../enums.dart';
import "../service.dart";

class Account extends Service {
    Account(Client client): super(client);

     /// Get Account
     ///
     /// Get currently logged in user data as JSON object.
     ///
    Future<Response> get() {
        final String path = '/account';

        final Map<String, dynamic> params = {
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.get, path: path, params: params, headers: headers);
    }

     /// Create Account
     ///
     /// Use this endpoint to allow a new user to register a new account in your
     /// project. After the user registration completes successfully, you can use
     /// the [/account/verfication](/docs/account#createVerification) route to start
     /// verifying the user email address. To allow your new user to login to his
     /// new account, you need to create a new [account
     /// session](/docs/account#createSession).
     ///
    Future<Response> create({@required String email, @required String password, String name = ''}) {
        final String path = '/account';

        final Map<String, dynamic> params = {
            'email': email,
            'password': password,
            'name': name,
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.post, path: path, params: params, headers: headers);
    }

     /// Delete Account
     ///
     /// Delete a currently logged in user account. Behind the scene, the user
     /// record is not deleted but permanently blocked from any access. This is done
     /// to avoid deleted accounts being overtaken by new users with the same email
     /// address. Any user-related resources like documents or storage files should
     /// be deleted separately.
     ///
    Future<Response> delete() {
        final String path = '/account';

        final Map<String, dynamic> params = {
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.delete, path: path, params: params, headers: headers);
    }

     /// Update Account Email
     ///
     /// Update currently logged in user account email address. After changing user
     /// address, user confirmation status is being reset and a new confirmation
     /// mail is sent. For security measures, user password is required to complete
     /// this request.
     ///
    Future<Response> updateEmail({@required String email, @required String password}) {
        final String path = '/account/email';

        final Map<String, dynamic> params = {
            'email': email,
            'password': password,
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.patch, path: path, params: params, headers: headers);
    }

     /// Get Account Logs
     ///
     /// Get currently logged in user list of latest security activity logs. Each
     /// log returns user IP address, location and date and time of log.
     ///
    Future<Response> getLogs() {
        final String path = '/account/logs';

        final Map<String, dynamic> params = {
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.get, path: path, params: params, headers: headers);
    }

     /// Update Account Name
     ///
     /// Update currently logged in user account name.
     ///
    Future<Response> updateName({@required String name}) {
        final String path = '/account/name';

        final Map<String, dynamic> params = {
            'name': name,
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.patch, path: path, params: params, headers: headers);
    }

     /// Update Account Password
     ///
     /// Update currently logged in user password. For validation, user is required
     /// to pass the password twice.
     ///
    Future<Response> updatePassword({@required String password, @required String oldPassword}) {
        final String path = '/account/password';

        final Map<String, dynamic> params = {
            'password': password,
            'old-password': oldPassword,
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.patch, path: path, params: params, headers: headers);
    }

     /// Get Account Preferences
     ///
     /// Get currently logged in user preferences as a key-value object.
     ///
    Future<Response> getPrefs() {
        final String path = '/account/prefs';

        final Map<String, dynamic> params = {
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.get, path: path, params: params, headers: headers);
    }

     /// Update Account Preferences
     ///
     /// Update currently logged in user account preferences. You can pass only the
     /// specific settings you wish to update.
     ///
    Future<Response> updatePrefs({@required dynamic prefs}) {
        final String path = '/account/prefs';

        final Map<String, dynamic> params = {
            'prefs': prefs,
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.patch, path: path, params: params, headers: headers);
    }

     /// Create Password Recovery
     ///
     /// Sends the user an email with a temporary secret key for password reset.
     /// When the user clicks the confirmation link he is redirected back to your
     /// app password reset URL with the secret key and email address values
     /// attached to the URL query string. Use the query string params to submit a
     /// request to the [PUT /account/recovery](/docs/account#updateRecovery)
     /// endpoint to complete the process.
     ///
    Future<Response> createRecovery({@required String email, @required String url}) {
        final String path = '/account/recovery';

        final Map<String, dynamic> params = {
            'email': email,
            'url': url,
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.post, path: path, params: params, headers: headers);
    }

     /// Complete Password Recovery
     ///
     /// Use this endpoint to complete the user account password reset. Both the
     /// **userId** and **secret** arguments will be passed as query parameters to
     /// the redirect URL you have provided when sending your request to the [POST
     /// /account/recovery](/docs/account#createRecovery) endpoint.
     /// 
     /// Please note that in order to avoid a [Redirect
     /// Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     /// the only valid redirect URLs are the ones from domains you have set when
     /// adding your platforms in the console interface.
     ///
    Future<Response> updateRecovery({@required String userId, @required String secret, @required String passwordA, @required String passwordB}) {
        final String path = '/account/recovery';

        final Map<String, dynamic> params = {
            'userId': userId,
            'secret': secret,
            'password-a': passwordA,
            'password-b': passwordB,
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.put, path: path, params: params, headers: headers);
    }

     /// Get Account Sessions
     ///
     /// Get currently logged in user list of active sessions across different
     /// devices.
     ///
    Future<Response> getSessions() {
        final String path = '/account/sessions';

        final Map<String, dynamic> params = {
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.get, path: path, params: params, headers: headers);
    }

     /// Create Account Session
     ///
     /// Allow the user to login into his account by providing a valid email and
     /// password combination. This route will create a new session for the user.
     ///
    Future<Response> createSession({@required String email, @required String password}) {
        final String path = '/account/sessions';

        final Map<String, dynamic> params = {
            'email': email,
            'password': password,
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.post, path: path, params: params, headers: headers);
    }

     /// Delete All Account Sessions
     ///
     /// Delete all sessions from the user account and remove any sessions cookies
     /// from the end client.
     ///
    Future<Response> deleteSessions() {
        final String path = '/account/sessions';

        final Map<String, dynamic> params = {
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.delete, path: path, params: params, headers: headers);
    }

     /// Create Account Session with OAuth2
     ///
     /// Allow the user to login to his account using the OAuth2 provider of his
     /// choice. Each OAuth2 provider should be enabled from the Appwrite console
     /// first. Use the success and failure arguments to provide a redirect URL's
     /// back to your app when login is completed.
     ///
    Future createOAuth2Session({@required String provider, String success = 'https://localhost:2444/auth/oauth2/success', String failure = 'https://localhost:2444/auth/oauth2/failure'}) {
        final String path = '/account/sessions/oauth2/{provider}'.replaceAll(RegExp('{provider}'), provider);

        final Map<String, dynamic> params = {
            'success': success,
            'failure': failure,
            'project': client.config['project'],
        };

        Uri endpoint = Uri.parse(client.endPoint);
        Uri url = new Uri(scheme: endpoint.scheme,
          host: endpoint.host,
          port: endpoint.port,
          path: endpoint.path + path,
          queryParameters:params,
        );

        return FlutterWebAuth.authenticate(
          url: url.toString(),
          callbackUrlScheme: "appwrite-callback"
          ).then((value) {
              Uri url = Uri.parse(value);
                List<Cookie> cookies = [new Cookie(url.queryParameters['key'], url.queryParameters['secret'])];
                client.cookieJar.saveFromResponse(Uri.parse(client.endPoint), cookies);
          });
    }

     /// Delete Account Session
     ///
     /// Use this endpoint to log out the currently logged in user from all his
     /// account sessions across all his different devices. When using the option id
     /// argument, only the session unique ID provider will be deleted.
     ///
    Future<Response> deleteSession({@required String sessionId}) {
        final String path = '/account/sessions/{sessionId}'.replaceAll(RegExp('{sessionId}'), sessionId);

        final Map<String, dynamic> params = {
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.delete, path: path, params: params, headers: headers);
    }

     /// Create Email Verification
     ///
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
     ///
    Future<Response> createVerification({@required String url}) {
        final String path = '/account/verification';

        final Map<String, dynamic> params = {
            'url': url,
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.post, path: path, params: params, headers: headers);
    }

     /// Complete Email Verification
     ///
     /// Use this endpoint to complete the user email verification process. Use both
     /// the **userId** and **secret** parameters that were attached to your app URL
     /// to verify the user email ownership. If confirmed this route will return a
     /// 200 status code.
     ///
    Future<Response> updateVerification({@required String userId, @required String secret}) {
        final String path = '/account/verification';

        final Map<String, dynamic> params = {
            'userId': userId,
            'secret': secret,
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.put, path: path, params: params, headers: headers);
    }
}