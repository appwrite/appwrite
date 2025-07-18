package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/users"
)

func main() {
    client := client.New(
        client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
        client.WithProject("<YOUR_PROJECT_ID>") // Your project ID
        client.WithKey("<YOUR_API_KEY>") // Your secret API key
    )

    service := users.New(client)
    response, error := service.CreateScryptUser(
        "<USER_ID>",
        "email@example.com",
        "password",
        "<PASSWORD_SALT>",
        0,
        0,
        0,
        0,
        users.WithCreateScryptUserName("<NAME>"),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
