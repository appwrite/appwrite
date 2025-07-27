package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/grids"
)

func main() {
    client := client.New(
        client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
        client.WithProject("<YOUR_PROJECT_ID>") // Your project ID
        client.WithKey("<YOUR_API_KEY>") // Your secret API key
    )

    service := grids.New(client)
    response, error := service.CreateTable(
        "<DATABASE_ID>",
        "<TABLE_ID>",
        "<NAME>",
        grids.WithCreateTablePermissions(interface{}{"read("any")"}),
        grids.WithCreateTableRowSecurity(false),
        grids.WithCreateTableEnabled(false),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
