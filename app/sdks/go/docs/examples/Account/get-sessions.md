# Account Examples

## GetSessions

```go
    package appwrite-getsessions

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

        // Create a new Account service passing Client
        var srv := appwrite.Account{
            client: &clt
        }

        // Call GetSessions method and handle results
        var res, err := srv.GetSessions()
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```