# Account Examples

## GetAccountLogs

```go
    package appwrite-getaccountlogs

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

        // Create a new Account service passing Client
        var srv := appwrite.Account{
            client: &clt
        }

        // Call GetAccountLogs method and handle results
        var res, err := srv.GetAccountLogs()
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```