package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/functions"
)

func main() {
    client := client.New(
        client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
        client.WithProject("<YOUR_PROJECT_ID>") // Your project ID
        client.WithKey("<YOUR_API_KEY>") // Your secret API key
    )

    service := functions.New(client)
    response, error := service.UpdateVariable(
        "<FUNCTION_ID>",
        "<VARIABLE_ID>",
        "<KEY>",
        functions.WithUpdateVariableValue("<VALUE>"),
        functions.WithUpdateVariableSecret(false),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
