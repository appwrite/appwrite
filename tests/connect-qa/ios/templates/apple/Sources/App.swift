// QA harness only - not part of the Connect snippet.
//
// A real Xcode project already has its own @main App struct, so the snippet
// starts at ContentView. The generated project needs an entry point, so the
// harness supplies the same boilerplate Xcode's template would.
import SwiftUI

@main
struct ConnectQAApp: App {
  var body: some Scene {
    WindowGroup {
      ContentView()
    }
  }
}
