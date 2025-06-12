package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/sites"
)

func main() {
    client := client.New(
        client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
        client.WithProject("<YOUR_PROJECT_ID>") // Your project ID
        client.WithKey("<YOUR_API_KEY>") // Your secret API key
    )

    service := sites.New(client)
    response, error := service.Update(
        "<SITE_ID>",
        "<NAME>",
        "analog",
        sites.WithUpdateEnabled(false),
        sites.WithUpdateLogging(false),
        sites.WithUpdateTimeout(1),
        sites.WithUpdateInstallCommand("<INSTALL_COMMAND>"),
        sites.WithUpdateBuildCommand("<BUILD_COMMAND>"),
        sites.WithUpdateOutputDirectory("<OUTPUT_DIRECTORY>"),
        sites.WithUpdateBuildRuntime("node-14.5"),
        sites.WithUpdateAdapter("static"),
        sites.WithUpdateFallbackFile("<FALLBACK_FILE>"),
        sites.WithUpdateInstallationId("<INSTALLATION_ID>"),
        sites.WithUpdateProviderRepositoryId("<PROVIDER_REPOSITORY_ID>"),
        sites.WithUpdateProviderBranch("<PROVIDER_BRANCH>"),
        sites.WithUpdateProviderSilentMode(false),
        sites.WithUpdateProviderRootDirectory("<PROVIDER_ROOT_DIRECTORY>"),
        sites.WithUpdateSpecification(""),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
