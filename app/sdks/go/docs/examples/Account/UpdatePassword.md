# Account Examples

## UpdatePassword

```go
    package appwrite-updatepassword

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

        // Call UpdatePassword method and handle results
        var res, err := srv.UpdatePassword("password", "password")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```