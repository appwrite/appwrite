# Account Examples

## UpdateAccountPassword

```go
    package appwrite-updateaccountpassword

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

        // Call UpdateAccountPassword method and handle results
        var res, err := srv.UpdateAccountPassword("password", "password")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```