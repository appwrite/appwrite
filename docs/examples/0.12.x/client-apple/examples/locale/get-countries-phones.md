import Appwrite

func main() {
    let client = Client()
      .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID

    let locale = Locale(client)
    locale.getCountriesPhones() { result in
        switch result {
        case .failure(let error):
            print(error.message)
        case .success(let phoneList):
            print(String(describing: phoneList)
        }
    }
}
