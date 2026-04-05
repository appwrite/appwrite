import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
      .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key
    let functions = Functions(client)
    let function = try await functions.create(
        functionId: "[FUNCTION_ID]",
        name: "[NAME]",
        execute: [],
        runtime: "node-14.5"
    )

    print(String(describing: function)
}
