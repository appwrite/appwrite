package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/functions"
)

client := client.New(
    client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    client.WithProject("<YOUR_PROJECT_ID>")
    client.WithKey("<YOUR_API_KEY>")
)

service := functions.New(client)

response, error := service.Create(
    "<FUNCTION_ID>",
    "<NAME>",
    "node-14.5",
    functions.WithCreateExecute(interface{}{"any"}),
    functions.WithCreateEvents([]interface{}{}),
    functions.WithCreateSchedule(""),
    functions.WithCreateTimeout(1),
    functions.WithCreateEnabled(false),
    functions.WithCreateLogging(false),
    functions.WithCreateEntrypoint("<ENTRYPOINT>"),
    functions.WithCreateCommands("<COMMANDS>"),
    functions.WithCreateScopes([]interface{}{}),
    functions.WithCreateInstallationId("<INSTALLATION_ID>"),
    functions.WithCreateProviderRepositoryId("<PROVIDER_REPOSITORY_ID>"),
    functions.WithCreateProviderBranch("<PROVIDER_BRANCH>"),
    functions.WithCreateProviderSilentMode(false),
    functions.WithCreateProviderRootDirectory("<PROVIDER_ROOT_DIRECTORY>"),
    functions.WithCreateSpecification(""),
)
