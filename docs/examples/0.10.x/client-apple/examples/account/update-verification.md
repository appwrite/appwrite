```swift
import Appwrite

func main() {
    let client = Client()
      .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID

    let account = Account(client: client)
    account.updateVerification(
        userId: "[USER_ID]",
        secret: "[SECRET]"
    ) { result in
        switch result {
        case .failure(let error):
            print(error.message)
        case .success(let token):
            print(String(describing: token)
        }
    }
}
```