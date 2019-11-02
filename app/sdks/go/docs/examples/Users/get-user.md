# Users Examples

## GetUser

```go
    package appwrite-getuser

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

        // Create a new Users service passing Client
        var srv := appwrite.Users{
            client: &clt
        }

        // Call GetUser method and handle results
        var res, err := srv.GetUser("[USER_ID]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```