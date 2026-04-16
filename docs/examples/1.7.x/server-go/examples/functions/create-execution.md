package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/functions"
)

func main() {
    client := client.New(
        client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
        client.WithProject("<YOUR_PROJECT_ID>") // Your project ID
        client.WithSession("") // The user session to authenticate with
    )

    service := functions.New(client)
    response, error := service.CreateExecution(
        "<FUNCTION_ID>",
        functions.WithCreateExecutionBody("<BODY>"),
        functions.WithCreateExecutionAsync(false),
        functions.WithCreateExecutionPath("<PATH>"),
        functions.WithCreateExecutionMethod("GET"),
        functions.WithCreateExecutionHeaders(map[string]interface{}{}),
        functions.WithCreateExecutionScheduledAt(""),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
