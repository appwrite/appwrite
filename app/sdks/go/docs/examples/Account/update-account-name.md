# Account Examples

## UpdateAccountName

```go
    package appwrite-updateaccountname

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

        // Call UpdateAccountName method and handle results
        var res, err := srv.UpdateAccountName("[NAME]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```