import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
      .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key
    let functions = Functions(client)
    let variable = try await functions.updateVariable(
        functionId: "[FUNCTION_ID]",
        variableId: "[VARIABLE_ID]",
        key: "[KEY]"
    )

    print(String(describing: variable)
}
