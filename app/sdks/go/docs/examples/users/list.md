package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go"
)

func main() {
    var client := appwrite.Client{}

    client.SetProject("")
    client.SetKey("")

    var service := appwrite.Users{
        client: &client
    }

    var response, error := service.List("[SEARCH]", 0, 0, "ASC")

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}