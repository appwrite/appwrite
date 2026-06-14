---
layout: post
title: "Swift 101: how to build a library with Swift Package Manager"
description: Learn how to build a library with Swift Package Manager.
date: 2024-08-02
cover: /images/blog/swift-101/cover.avif
timeToRead: 6
author: jake-barnby
category: engineering, tutorial
featured: false
faqs:
  - question: "What is Swift Package Manager?"
    answer: "Swift Package Manager (SwiftPM) is Swift's official tool for managing dependencies, building libraries, and producing executables. Starting with Swift 5 and Xcode 11, it works across the entire Apple ecosystem, not just server-side Swift."
  - question: "What is the difference between a Swift module and a Swift package?"
    answer: "A module is a single namespace of Swift code with its own visibility rules. A package is a collection of source files and a `Package.swift` manifest that can contain one or more targets (which become modules) and produce libraries or executables."
  - question: "How do I create a Swift library with SwiftPM?"
    answer: "Run `swift package init --type=library` inside an empty directory. SwiftPM scaffolds a `Sources` directory, a `Tests` directory, and a `Package.swift` manifest. You can then run `swift build` to compile the library and `swift test` to run its tests."
  - question: "What does a Package.swift manifest contain?"
    answer: "The `Package.swift` manifest declares your package's name, the products it produces (libraries or executables), the dependencies it pulls in, and the targets it builds. Each target maps to a module of source files and can depend on other targets or external products."
  - question: "Can I distribute a Swift package to other developers?"
    answer: "Yes. Push your package to a public Git repository and other developers can add it as a dependency by pointing to the repo URL and a version tag. SwiftPM will resolve, fetch, and build it automatically when they run `swift build` or open the project in Xcode."
  - question: "Does Appwrite have a Swift SDK?"
    answer: "Yes. Appwrite ships an official Swift SDK distributed through Swift Package Manager, so you can add it to iOS, macOS, or server Swift projects. You can use it with [Appwrite Auth](/docs/products/auth), [Databases](/docs/products/databases), [Storage](/docs/products/storage), and other products."
---

The Swift Package Manager, or SwiftPM, has been part of Swift since version 3.0. Initially, it was just for server-side or command-line Swift projects. However, starting with Swift 5 and Xcode 11, SwiftPM now works across the entire Apple ecosystem for building apps. This is great because packages let you organize your code into reusable, logical groups that you can easily share between projects or even with the world.

## Modules

Before looking at **packages**, we first need to understand **modules**. Swift organizes code into modules. Each module defines a namespace and which parts of the code can be used from outside the module. You can define all of your code in a single module or break it up into multiple modules that can depend on each other. Using modules lets you easily build on your own reusable code or others’ code.

## Packages

So, what is a Swift package? A package is a collection of Swift source code files as well as a manifest file called `Package.swift`, that defines various properties about the package, such as its name, the products it produces, any dependencies it has, and the targets it is built up of.

## Anatomy of a package

- **Products** define the libraries and executables produced by a package. A library is simply a collection of files for use as a dependency by other Swift code. An executable is a package that can be run, such as a web server.
- **Dependencies** are other Swift Packages you want to use code from within your package.
- **Targets** define the modules within a package. Each target specifies the code that makes up the module, and any dependencies. The dependencies can be other targets within the same package, or products from external packages.

## Creating a Swift package

> This tutorial assumes you already have Swift installed. You can check by running swift-help from your terminal.
>

Creating a Swift Package from the command line is easy and can be completed with one simple command from the directory in which you want to create your package. For this example, we'll start with a directory named `FooPackage`.

```
$ mkdir FooPackage
$ cd FooPackage

FooPackage$ swift package init --type=library

```

That’s it! There’ll be some output detailing the files created for your new package. You should see:

- 1 source file created inside a `Sources` directory
- 1 test source file inside a `Tests` directory
- A `Package.swift` manifest file at the root level
- A README.md file at the root level
- A .gitignore file at the root level

Of these files, only the single file in the `Sources` directory and the manifest file are required for the package to build. This means you could easily create your own package by manually creating these two files as well.

By default, the `Sources` directory must contain all source code for the package, but you can use sub-directories to define sub-modules if they are also defined as separate targets in your manifest file. Let's take a look at the generated `Package.swift` for the new package to see the pieces we've discussed so far:

```swift
// swift-tools-version:5.3
// The swift-tools-version declares the minimum version of Swift required to build this package.

import PackageDescription

let package = Package(
    name: "TestPackage",
    products: [
        .library(
            name: "FooPackage",
            targets: ["FooPackage"]),
    ],
    dependencies: [
    ],
    targets: [
        .target(
            name: "FooPackage",
            dependencies: [
            ]
        )
        .testTarget(
            name: "FooPackageTests",
            dependencies: [
                "FooPackage"
            ]
        )
    ]
)

```

Here, you can see that our package defines one **library**, `TestPackage`, one **target** of the same name, and one test target, which depends on the module target.

## The first build

Now that the package has been created let’s build it for the first time with the build command:

```
$ swift build

```

Because the package has no dependencies or code yet, this should be completed almost instantly, displaying “Build Completed!” on success.

## Adding dependencies

Let's add a dependency and some code. Adding dependencies with SwiftPM is easy as you can use git URL's directly. We can add the following to our `Package.swift` top-level dependencies block to allow us to the Appwrite Swift SDK in our library:

```swift
.package(name: "Appwrite", url: "https://github.com/appwrite/sdk-for-swift", from: "0.1.0")

```

This declares that our package will pull in the code from the `Appwrite` module in the `sdk-for-swift` repository on GitHub, from the tag `0.1.0` and allow us to add it to our target dependencies as follows:

```swift
.target(
    name: "FooPackage",
    dependencies: [
        "Appwrite"
    ]
)

```

Here, we added `Appwrite`, as this is the name of the library we're using from the `sdk-for-swift` repository.

Let's take a look at the full manifest file with the new dependency added:

```swift
// swift-tools-version:5.3
// The swift-tools-version declares the minimum version of Swift required to build this package.

import PackageDescription

let package = Package(
    name: "FooPackage",
    products: [
        .library(
            name: "FooPackage",
            targets: ["FooPackage"]),
    ],
    dependencies: [
        .package(name: "Appwrite", url: "https://github.com/appwrite/sdk-for-swift", from: "0.1.0")
    ],
    targets: [
        .target(
            name: "FooPackage",
            dependencies: [
                "Appwrite"
            ]
        )
        .testTarget(
            name: "FooPackageTests",
            dependencies: [
                "FooPackage"
            ]
        )
    ]
)

```

Since we've changed the dependencies of our package, we need to **resolve** them. This will happen automatically the first time you run `swift build` with a new dependency, but if you manually update a version, you'll need to manually resolve the new version. This can be done by running:

```
$ swift package resolve

```

This will update the `Package.resolved` to contain the version metadata about the `Appwrite` module we just added.

> **What's going on here?**
>
>
> Swift Package Manager uses a lockfile system similar to `package.lock` for NPM and `composer.lock` for Composer. This comes in the form of a file called `Package.resolved`, which contains metadata about the packages dependencies versions, as well as their transitive dependencies. When you run `swift build` and the dependencies are fetched, the versions from the `Package.resolved` file will be used if found.
>

Once resolved, we can build our package with `swift build` again. This time we'll see the `sdk-for-swift` repository pulled into the build checkouts, as well as built with the rest of the library.

## Adding library code

Time to add some code. Let's open up the source file created earlier as `Sources/FooPackage/FooPackage.swift` and update with the following:

```swift
import Appwrite

struct FooPackage {

    static let client = Client()
    static let account = Account(client)

    public static func login(
        endpoint: String,
        projectId: String,
        email: String,
        password: String,
        completion: @escaping (Result<Session, AppwriteError>) -> Void
    ) {
        client
            .setEndpoint(endpoint)
            .setProject(projectId)

        account.createSession(
            email: email,
            password: password,
            completion: completion
        )
    }
}

```

We now have a login function! We just need to deploy the package, and we'll be able to use the login function from any other package or Apple app.

## Deploying the package

Fortunately deploying packages with Swift Package Manager is very easy. As the packages are Git based, all you need to do is push your changes to your default branch and create a tag for your release:

```
$ git init
$ git add .
$ git remote add origin [GitHub Repository URL]
$ git commit -m "Initial Commit"
$ git tag 1.0.0
$ git push origin main --tags

```

## Using as a dependency

Using the same method we used earlier to add the Appwrite Apple SDK as a dependency, we can now add the newly deployed package as a dependency of a second package:

```swift
...

    dependencies: [
        .package(name: "FooPackage", url: "https://github.com/[YOUR GITHUB USERNAME]/[YOUR GITHUB REPOSITORY]", from: "1.0.0")
    ],
    targets: [
        .target(
            name: "FooPackage",
            dependencies: [
                "FooPackage"
            ]
        )
    ]

    ...

)

```

## Using the dependency

With the package added as a dependency, we can now use the function we defined earlier anywhere in the second package:

```swift
import FooPackage

FooPackage.login(
    endpoint: "http://localhost/v1",
    projectId: "6bfgh45fng3",
    email: "test@test.test",
    password: "password"
) { result in
    ...
}

```

## Updating your package

The process for updating your package is the same as deploying the initial version. You just need to push your changes to the default branch and add a new version tag.

## That's it!

You've now created, deployed, used and updated your very own Swift Package! Packages are a great way to re-use code and share your creations with the world.

## Resources

- [Swift](https://swift.org/)
- [Swift Package Manager](https://swift.org/package-manager/)
- [Creating Swift Packages with Xcode](https://developer.apple.com/documentation/xcode/creating_a_standalone_swift_package_with_xcode)
