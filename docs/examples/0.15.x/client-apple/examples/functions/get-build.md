import Appwrite

func main() {
    let client = Client()
      .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID

    let functions = Functions(client)
    functions.getBuild(
        functionId: "[FUNCTION_ID]",
        deploymentId: "[DEPLOYMENT_ID]",
        buildId: "[BUILD_ID]"
    ) { result in
        switch result {
        case .failure(let error):
            print(error.message)
        case .success(let build):
            print(String(describing: build)
        }
    }
}
