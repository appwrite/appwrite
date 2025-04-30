package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/sites"
)

func main() {
    client := client.NewClient()

    client.SetEndpoint("https://example.com/v1") // Your API Endpoint
    client.SetProject("<YOUR_PROJECT_ID>") // Your project ID
    client.SetKey("<YOUR_API_KEY>") // Your secret API key

    service := sites.NewSites(client)
    response, error := service.GetVariable(
        "<SITE_ID>",
        "<VARIABLE_ID>",
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
