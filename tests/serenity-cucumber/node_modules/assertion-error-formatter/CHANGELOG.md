# 3.0.0 (2019-08-20)

* drop support for Node 4, 6
* support Node 10, 12

# 2.0.1

* support object errors without stack
* better formatting for errors where the stack does not include the message

# 2.0.0

* support string errors
* update options structure
    ```js
    // Before
    {
      colorDiffAdded,
      colorDiffRemoved,
      colorErrorMessage,
      inlineDiffs
    }

    // After
    {
      colorFns: {
        diffAdded,
        diffRemoved,
        errorMessage,
        errorStack
      },
      inlineDiffs
    }
    ```

# 1.0.1

* Initial release
