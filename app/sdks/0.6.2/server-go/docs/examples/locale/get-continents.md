package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go"
)

func main() {
    var client := appwrite.Client{}

    client.SetProject("5df5acd0d48c2") // Your project ID
    client.SetKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

    var service := appwrite.Locale{
        client: &client
    }

    var response, error := service.GetContinents()

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}