# Auth Examples

## Confirm

```go
    package appwrite-confirm

    import (
        "fmt"
        "os"
        "github.com/appwrite/sdk-for-go"
    )

    func main() {
        // Create a Client
        var clt := appwrite.Client{}

        // Set Client required headers

        // Create a new Auth service passing Client
        var srv := appwrite.Auth{
            client: &clt
        }

        // Call Confirm method and handle results
        var res, err := srv.Confirm("[USER_ID]", "[TOKEN]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```