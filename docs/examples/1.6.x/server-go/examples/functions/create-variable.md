package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/functions"
)

func main() {
    client := client.NewClient()

    client.SetEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    client.SetProject("") // Your project ID
    client.SetKey("") // Your secret API key

    service := functions.NewFunctions(client)
    response, error := service.CreateVariable(
        "<FUNCTION_ID>",
        "<KEY>",
        "<VALUE>",
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
