# Auth Examples

## Oauth

```go
    package appwrite-oauth

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

        // Call Oauth method and handle results
        var res, err := srv.Oauth("bitbucket")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```