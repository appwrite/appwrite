import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
      .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key
    let functions = Functions(client)
    let execution = try await functions.getExecution(
        functionId: "[FUNCTION_ID]",
        executionId: "[EXECUTION_ID]"
    )

    print(String(describing: execution)
}
