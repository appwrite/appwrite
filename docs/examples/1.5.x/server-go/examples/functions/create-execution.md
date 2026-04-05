package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/functions"
)

func main() {
    client := client.NewClient()

    client.SetEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    client.SetProject("<YOUR_PROJECT_ID>") // Your project ID
    client.SetSession("") // The user session to authenticate with

    service := functions.NewFunctions(client)
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
