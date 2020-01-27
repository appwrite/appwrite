# Avatars Examples

## GetCreditCard

```go
    package appwrite-getcreditcard

    import (
        "fmt"
        "os"
        "github.com/appwrite/sdk-for-go"
    )

    func main() {
        // Create a Client
        var clt := appwrite.Client{}

        // Set Client required headers
        clt.SetProject("")

        // Create a new Avatars service passing Client
        var srv := appwrite.Avatars{
            client: &clt
        }

        // Call GetCreditCard method and handle results
        var res, err := srv.GetCreditCard("amex")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```