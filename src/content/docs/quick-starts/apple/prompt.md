## Add Appwrite Auth to an Apple (iOS/macOS/watchOS/tvOS) App

Goal: Add Appwrite auth to an Apple (iOS/macOS/watchOS/tvOS) app with a working login and register page using SwiftUI.

**Rules**

- Never assume project details. Ask the user for **Cloud Region**, **Project ID**, and **bundle identifier**.
- Use explicit config (no hardcoding except reading constants the user provides).
- Respect the user's Xcode project setup and Apple platform choice.

## Step 1: Create or Use Existing Apple Project

- If you already have an Xcode project open, stay in it and use it.
- Otherwise, guide the user to:
    - Open Xcode and click **Create a new Xcode project**
    - Choose the desired template (e.g., **iOS App**) and click **Next**
    - Enter app **product name** and **bundle identifier**, then click **Next**
    - Choose a directory and click **Create**

## Step 2: Create Appwrite Project

- Ask user to go to the [Appwrite Console](https://cloud.appwrite.io/console) and create a project if they haven't already.
- Under **Add a platform**, add an **Apple app** (iOS, macOS, watchOS, or tvOS).
- Add the app's **product name** and **bundle identifier** from the Xcode project.

## Step 3: Add the Appwrite SDK

- In Xcode, open the **File** menu and click **Add Packages**.
- In the **Package URL** search box, enter: `https://github.com/appwrite/sdk-for-apple`
- Use `10.1.0` as version, select **Up to Next Major Version** as **Dependency Rule**, and click **Add Package**.
- When resolution is complete, click **Add Package** again to add the SDK to the target.
- Ask the user for their **Project ID** and add the following URL scheme to `Info.plist` for OAuth sessions:

```xml
<key>CFBundleURLTypes</key>
<array>
<dict>
    <key>CFBundleTypeRole</key>
    <string>Editor</string>
    <key>CFBundleURLName</key>
    <string>io.appwrite</string>
    <key>CFBundleURLSchemes</key>
    <array>
        <string>appwrite-callback-<PROJECT_ID></string>
    </array>
</dict>
</array>
```

- If using UIKit instead of SwiftUI, add the following to `SceneDelegate.swift`:

```swift
func scene(_ scene: UIScene, openURLContexts URLContexts: Set<UIOpenURLContext>) {
    guard let url = URLContexts.first?.url,
        url.absoluteString.contains("appwrite-callback") else {
        return
    }

    WebAuthComponent.handleIncomingCookie(from: url)
}
```

## Step 4: Create Appwrite Singleton (Ask User for Details)

- Ask the user for:
    - Appwrite **Cloud Region** (e.g., `fra`, `nyc`)
    - **Project ID** (from Console -> Settings)
- Create file `Appwrite.swift` with the following code:

```swift
import Foundation
import Appwrite
import JSONCodable

class Appwrite {
    var client: Client
    var account: Account

    public init() {
        self.client = Client()
            .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
            .setProject("<PROJECT_ID>")

        self.account = Account(client)
    }

    public func onRegister(
        _ email: String,
        _ password: String
    ) async throws -> User<[String: AnyCodable]> {
        try await account.create(
            userId: ID.unique(),
            email: email,
            password: password
        )
    }

    public func onLogin(
        _ email: String,
        _ password: String
    ) async throws -> Session {
        try await account.createEmailPasswordSession(
            email: email,
            password: password
        )
    }

    public func onLogout() async throws {
        _ = try await account.deleteSession(
            sessionId: "current"
        )
    }

}
```

## Step 5: Create a Login Page

- Add the following code to `ContentView.swift`:

```swift
import SwiftUI

class ViewModel: ObservableObject {
    @Published var email: String = ""
    @Published var password: String = ""
}

struct ContentView: View {
    @StateObject var viewModel = ViewModel()
    let appwrite = Appwrite()

    var body: some View {
        VStack {
            TextField(
                "Email",
                text: $viewModel.email
            )
            SecureField(
                "Password",
                text: $viewModel.password
            )
            Button(
                action: { Task {
                    try await appwrite.onRegister(
                        viewModel.email,
                        viewModel.password
                    )
                }},
                label: {
                    Text("Register")
                }
            )
            Button(
                action: { Task {
                    try await appwrite.onLogin(
                        viewModel.email,
                        viewModel.password
                    )
                }},
                label: {
                    Text("Login")
                }
            )
        }
        .padding()
    }
}
```

## Step 6: Run and Test

- Run the project by clicking **Start active scheme** in Xcode.
- Test flows:
    - Register a new user
    - Login with the registered user
- Surface any Appwrite errors (invalid project, endpoint, platform mismatch) and fix by guiding updates to `Appwrite.swift` and Console settings.

## Deliverables

- A running Apple app with working Appwrite auth (register/login/logout)
- Files created/updated: `Appwrite.swift`, `ContentView.swift`, `Info.plist` (URL scheme)
