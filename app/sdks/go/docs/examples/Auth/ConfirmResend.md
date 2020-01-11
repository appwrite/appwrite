# Auth Examples

## ConfirmResend

```go
    package appwrite-confirmresend

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
        clt.SetKey("")

        // Create a new Auth service passing Client
        var srv := appwrite.Auth{
            client: &clt
        }

        // Call ConfirmResend method and handle results
        var res, err := srv.ConfirmResend("https://example.com")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```