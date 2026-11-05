// swift-tools-version:5.9
// Minimal executable package for the Swift connect snippet; the workflow
// copies the snippet's main.swift into Sources/ and runs `swift run`.
import PackageDescription

let package = Package(
    name: "ConnectQA",
    dependencies: [
        .package(
            url: "https://github.com/appwrite/sdk-for-swift",
            from: "20.0.0"
        )
    ],
    targets: [
        .executableTarget(
            name: "ConnectQA",
            dependencies: [
                .product(name: "Appwrite", package: "sdk-for-swift")
            ],
            path: "Sources"
        )
    ]
)
