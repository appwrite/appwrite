package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go"
)

func main() {
    var client := appwrite.Client{}

    client.SetProject("")
    client.SetKey("")

    var service := appwrite.Storage{
        client: &client
    }

    var response, error := service.Create(file, [], [])

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}