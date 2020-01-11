# Account Examples

## UpdateName

```go
    package appwrite-updatename

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

        // Call UpdateName method and handle results
        var res, err := srv.UpdateName("[NAME]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```