// swift-tools-version:5.9
// Minimal runnable Vapor package for the Swift connect snippet; the workflow
// copies the snippet's Sources/ on top and runs `swift run`.
import PackageDescription

let package = Package(
    name: "ConnectQA",
    platforms: [
        .macOS(.v13)
    ],
    dependencies: [
        .package(url: "https://github.com/vapor/vapor", from: "4.122.0"),
        .package(
            url: "https://github.com/appwrite/sdk-for-swift",
            from: "20.0.0"
        )
    ],
    targets: [
        .executableTarget(
            name: "ConnectQA",
            dependencies: [
                .product(name: "Vapor", package: "vapor"),
                .product(name: "Appwrite", package: "sdk-for-swift")
            ],
            path: "Sources"
        )
    ]
)
