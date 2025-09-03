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

response, error := service.CreateDeployment(
    "<FUNCTION_ID>",
    file.NewInputFile("/path/to/file.png", "file.png"),
    false,
    functions.WithCreateDeploymentEntrypoint("<ENTRYPOINT>"),
    functions.WithCreateDeploymentCommands("<COMMANDS>"),
)
