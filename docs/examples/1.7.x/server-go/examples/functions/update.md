package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/functions"
)

func main() {
    client := client.NewClient()

    client.SetEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    client.SetProject("<YOUR_PROJECT_ID>") // Your project ID
    client.SetKey("<YOUR_API_KEY>") // Your secret API key

    service := functions.NewFunctions(client)
    response, error := service.Update(
        "<FUNCTION_ID>",
        "<NAME>",
        functions.WithUpdateRuntime("node-14.5"),
        functions.WithUpdateExecute(interface{}{"any"}),
        functions.WithUpdateEvents([]interface{}{}),
        functions.WithUpdateSchedule(""),
        functions.WithUpdateTimeout(1),
        functions.WithUpdateEnabled(false),
        functions.WithUpdateLogging(false),
        functions.WithUpdateEntrypoint("<ENTRYPOINT>"),
        functions.WithUpdateCommands("<COMMANDS>"),
        functions.WithUpdateScopes([]interface{}{}),
        functions.WithUpdateInstallationId("<INSTALLATION_ID>"),
        functions.WithUpdateProviderRepositoryId("<PROVIDER_REPOSITORY_ID>"),
        functions.WithUpdateProviderBranch("<PROVIDER_BRANCH>"),
        functions.WithUpdateProviderSilentMode(false),
        functions.WithUpdateProviderRootDirectory("<PROVIDER_ROOT_DIRECTORY>"),
        functions.WithUpdateSpecification(""),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
