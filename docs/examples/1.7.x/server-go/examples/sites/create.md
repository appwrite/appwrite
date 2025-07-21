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
    response, error := service.Create(
        "<SITE_ID>",
        "<NAME>",
        "analog",
        "node-14.5",
        sites.WithCreateEnabled(false),
        sites.WithCreateLogging(false),
        sites.WithCreateTimeout(1),
        sites.WithCreateInstallCommand("<INSTALL_COMMAND>"),
        sites.WithCreateBuildCommand("<BUILD_COMMAND>"),
        sites.WithCreateOutputDirectory("<OUTPUT_DIRECTORY>"),
        sites.WithCreateAdapter("static"),
        sites.WithCreateInstallationId("<INSTALLATION_ID>"),
        sites.WithCreateFallbackFile("<FALLBACK_FILE>"),
        sites.WithCreateProviderRepositoryId("<PROVIDER_REPOSITORY_ID>"),
        sites.WithCreateProviderBranch("<PROVIDER_BRANCH>"),
        sites.WithCreateProviderSilentMode(false),
        sites.WithCreateProviderRootDirectory("<PROVIDER_ROOT_DIRECTORY>"),
        sites.WithCreateSpecification(""),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
