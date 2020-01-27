# Account Examples

## DeleteAccountCurrentSession

```go
    package appwrite-deleteaccountcurrentsession

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

        // Call DeleteAccountCurrentSession method and handle results
        var res, err := srv.DeleteAccountCurrentSession()
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```