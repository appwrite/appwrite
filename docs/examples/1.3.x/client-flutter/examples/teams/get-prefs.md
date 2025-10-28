import 'package:appwrite/appwrite.dart';

void main() { // Init SDK
  Client client = Client();
  Teams teams = Teams(client);

  client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
  ;
  Future result = teams.getPrefs(
    teamId: '[TEAM_ID]',
  );

  result
    .then((response) {
      print(response);
    }).catchError((error) {
      print(error.response);
  });
}
