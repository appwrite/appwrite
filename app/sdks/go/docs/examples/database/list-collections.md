package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go"
)

func main() {
    var client := appwrite.Client{}

    client.SetProject("5df5acd0d48c2")
    client.SetKey("919c2d18fb5d4...a2ae413da83346ad2")

    var service := appwrite.Database{
        client: &client
    }

    var response, error := service.ListCollections("[SEARCH]", 0, 0, "ASC")

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}