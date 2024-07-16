package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/users"
)

func main() {
    client := client.NewClient()

    client.SetEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    client.SetProject("") // Your project ID
    client.SetKey("") // Your secret API key

    service := users.NewUsers(client)
    response, error := service.ListIdentities(
        users.WithListIdentitiesQueries([]interface{}{}),
        users.WithListIdentitiesSearch("<SEARCH>"),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
