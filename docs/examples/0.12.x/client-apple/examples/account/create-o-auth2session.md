import Appwrite

func main() {
    let client = Client()
      .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID

    let account = Account(client)
    account.createOAuth2Session(
        provider: "amazon"
    ) { result in
        switch result {
        case .failure(let error):
            print(error.message)
        case .success(let success):
            print(String(describing: success)
        }
    }
}
