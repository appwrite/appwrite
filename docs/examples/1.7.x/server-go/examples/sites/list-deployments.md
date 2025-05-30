package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/sites"
)

func main() {
    client := client.NewClient()

    client.SetEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    client.SetProject("<YOUR_PROJECT_ID>") // Your project ID
    client.SetKey("<YOUR_API_KEY>") // Your secret API key

    service := sites.NewSites(client)
    response, error := service.ListDeployments(
        "<SITE_ID>",
        sites.WithListDeploymentsQueries([]interface{}{}),
        sites.WithListDeploymentsSearch("<SEARCH>"),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
