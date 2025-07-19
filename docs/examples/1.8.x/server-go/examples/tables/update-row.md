package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/tables"
)

func main() {
    client := client.New(
        client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
        client.WithProject("<YOUR_PROJECT_ID>") // Your project ID
        client.WithSession("") // The user session to authenticate with
    )

    service := tables.New(client)
    response, error := service.UpdateRow(
        "<DATABASE_ID>",
        "<TABLE_ID>",
        "<ROW_ID>",
        tables.WithUpdateRowData(map[string]interface{}{}),
        tables.WithUpdateRowPermissions(interface{}{"read("any")"}),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
