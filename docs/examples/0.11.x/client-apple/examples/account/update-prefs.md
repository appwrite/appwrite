import Appwrite

func main() {
    let client = Client()
      .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID

    let account = Account(client)
    account.updatePrefs(
        prefs: 
    ) { result in
        switch result {
        case .failure(let error):
            print(error.message)
        case .success(let user):
            print(String(describing: user)
        }
    }
}
