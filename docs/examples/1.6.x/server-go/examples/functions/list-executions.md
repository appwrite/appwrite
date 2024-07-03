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
    client.SetSession("") // The user session to authenticate with

    service := functions.NewFunctions(client)
    response, error := service.ListExecutions(
        "<FUNCTION_ID>",
        functions.WithListExecutionsQueries([]interface{}{}),
        functions.WithListExecutionsSearch("<SEARCH>"),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
