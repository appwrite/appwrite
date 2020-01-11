# Auth Examples

## Recovery

```go
    package appwrite-recovery

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

        // Call Recovery method and handle results
        var res, err := srv.Recovery("email@example.com", "https://example.com")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```