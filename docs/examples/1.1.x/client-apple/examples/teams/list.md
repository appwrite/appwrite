import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
    let teams = Teams(client)
    let teamList = try await teams.list()

    print(String(describing: teamList)
}
