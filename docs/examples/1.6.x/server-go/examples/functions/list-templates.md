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

    service := functions.NewFunctions(client)
    response, error := service.ListTemplates(
        functions.WithListTemplatesRuntimes([]interface{}{}),
        functions.WithListTemplatesUseCases([]interface{}{}),
        functions.WithListTemplatesLimit(1),
        functions.WithListTemplatesOffset(0),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
