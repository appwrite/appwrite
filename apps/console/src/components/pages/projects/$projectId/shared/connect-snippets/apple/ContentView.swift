import Appwrite
import SwiftUI

struct ContentView: View {
  @State private var route = "home"

  var body: some View {
    VStack(spacing: 8) {
      switch route {
      case "sign-in":
        SignInView(
          onSignedIn: { route = "home" },
          onGoToSignUp: { route = "sign-up" }
        )
      case "sign-up":
        SignUpView(
          onSignedUp: { route = "home" },
          onGoToSignIn: { route = "sign-in" }
        )
      default:
        HomeView(
          onGoToSignIn: { route = "sign-in" },
          onGoToSignUp: { route = "sign-up" }
        )
      }
    }
    .padding(24)
  }
}

struct HomeView: View {
  let onGoToSignIn: () -> Void
  let onGoToSignUp: () -> Void

  @State private var name: String?
  @State private var loading = true

  var body: some View {
    Group {
      if loading {
        Text("Loading...")
      } else if let name {
        Text("Hello, \(name)")
        Button("Sign out", action: signOut)
      } else {
        Text("Sign in to get started.")
        Button("Sign in", action: onGoToSignIn)
        Button("Sign up", action: onGoToSignUp)
      }
    }
    .task {
      name = try? await Account(client).get().name
      loading = false
    }
  }

  private func signOut() {
    Task {
      try? await Account(client).deleteSession(sessionId: "current")
      name = nil
    }
  }
}
