import 'package:appwrite/appwrite.dart';

void main() { // Init SDK
  Client client = Client();
  Users users = Users(client);

  client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
  ;
  Future result = users.deleteAuthenticator(
    userId:'[USER_ID]' ,
    provider: AuthenticatorProvider.totp.value,
    otp:'[OTP]' ,
  );

  result
    .then((response) {
      print(response);
    }).catchError((error) {
      print(error.response);
  });
}
