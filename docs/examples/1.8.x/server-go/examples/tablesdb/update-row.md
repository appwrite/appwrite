package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/tablesdb"
)

client := client.New(
    client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    client.WithProject("<YOUR_PROJECT_ID>")
    client.WithSession("")
)

service := tablesdb.New(client)

response, error := service.UpdateRow(
    "<DATABASE_ID>",
    "<TABLE_ID>",
    "<ROW_ID>",
    tablesdb.WithUpdateRowData(map[string]interface{}{
        "username": "walter.obrien",
        "email": "walter.obrien@example.com",
        "fullName": "Walter O'Brien",
        "age": 33,
        "isAdmin": false
    }),
    tablesdb.WithUpdateRowPermissions(interface{}{"read("any")"}),
    tablesdb.WithUpdateRowTransactionId("<TRANSACTION_ID>"),
)
