---
layout: article
title: Start with Authentication
description: Effortlessly add authentication to your apps - simple signup & login in just minutes with Appwrite Authentication
---
You can get up and running with Appwrite Authentication in minutes.
You can add basic email and password authentication to your app with just a few lines of code.

{% section #sign-up step=1 title="Signup" %}
You can use the Appwrite [Client SDKs](/docs/sdks#client) to create an account using email and password.

{% multicode %}
```client-web
import { Client, Account, ID } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>');                 // Your project ID

const account = new Account(client);

const user = await account.create({
    userId: ID.unique(), 
    email: 'email@example.com', 
    password: 'password'
});
```
```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>');                 // Your project ID

final account = Account(client);

final user = await account.create(
    userId: ID.unique(),
    email: 'email@example.com',
    password: 'password',
);
```
```client-apple
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                  // Your project ID

let account = Account(client)

let user = try await account.create(
    userId: ID.unique(),
    email: "email@example.com",
    password: "password"
)
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Account
import io.appwrite.ID

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                  // Your project ID

val account = Account(client)

val user = account.create(
    userId = ID.unique(),
    email = "email@example.com",
    password = "password"
)
```
```graphql
mutation {
    accountCreate(userId: "unique()", email: "email@example.com", password: "password") {
        _id
        email
        name
    }
}
```

```client-react-native
import { Client, Account, ID } from "appwrite";
const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>');                 // Your project ID

const account = new Account(client);

const user = await account.create({
    userId: ID.unique(), 
    email: 'email@example.com', 
    password: 'password'
});
```
{% /multicode %}
{% /section %}

{% section #login step=2 title="Login" %}

After you've created your account, users can be logged in using the [Create Email Session](/docs/references/cloud/client-web/account#createEmailPasswordSession) method.

{% multicode %}
```client-web
const session = await account.createEmailPasswordSession({
    email: email, 
    password: password
});
```

```client-flutter
final session = await account.createEmailPasswordSession(
    email: 'email@example.com',
    password: 'password'
);
```

```client-apple
let session = try await account.createEmailPasswordSession(
    email: "email@example.com",
    password: "password"
)
```

```client-android-kotlin
val session = account.createEmailPasswordSession(
    email = "email@example.com",
    password = "password"
)
```

```graphql
mutation {
    accountcreateEmailPasswordSession(email: "email@example.com", password: "password") {
        _id
        userId
        provider
        expire
    }
}
```

```client-react-native
const session = await account.createEmailPasswordSession({
    email: email, 
    password: password
});
```
{% /multicode %}
{% /section %}

{% section #check-authentication-state step=3 title="Check authentication state" %}
After logging in, you can check the authentication state of the user.

Appwrite's SDKs are stateless, so you need to manage the session state in your app. 
You can use the [Get Account](/docs/references/cloud/client-web/account#get) method to check if the user is logged in.

{% multicode %}
```client-web
try {
    const user = await account.get();
    // Logged in
} catch (err) {
    // Not logged in
}
```
```client-flutter
try {
    final user = await account.get();
    // Logged in
} catch(e) {
    // Not logged in
}
```
```client-apple
do {
    let user = try account.get()
    // Logged in
} catch {
    // Not logged in
}
```
```client-android-kotlin
return try {
    val user = account.get()
    // Logged in
} catch (e: AppwriteException) {
    // Not logged in
}
```
```graphql
query {
    accountGet {
        _id
        _createdAt
        _updatedAt
        name
        registration
        status
        labels
        passwordUpdate
        email
        phone
        emailVerification
        phoneVerification
        prefs {
            data
        }
        accessedAt
    }
}
```
```client-react-native
try {
    const user = await account.get();
    // Logged in
} catch (err) {
    // Not logged in
}
```
{% /multicode %}
{% /section %}

{% section #auth-and-nav step=4 title="Navigation (Optional)" %}
A common pattern is to use route guards to redirect users to the login page if they are not authenticated.
You can check the authentication state on app launch and before entering a protected route by calling `get()`.

Route guard implementations are **opinionated** and depend on the platform and frame you are using. 
Take a look at some example usages from different platforms as inspiration.

{% accordion %}
{% accordion_item title="Web frameworks" %}
Before routing to a page, you can check if the user is logged in and redirect them to the login page if they are not.

{% tabs %}
{% tabsitem #react-router title="React router" %}
You can use [React router loaders](https://reactrouter.com/en/main/route/loader) to check if the user is logged in before rendering a route.

You can find a similar example in this [YouTube video](https://youtu.be/pyfwQUc5Ssk).

```client-web 
import * as React from "react";
import {
  createBrowserRouter,
} from "react-router-dom";
import "./index.css";

import Login from "./Login";
import Protected from "./Protected";
import { account } from "./lib/appwrite";
import { redirect } from "react-router-dom";


const router = createBrowserRouter([
  {
    path: "/protected",
    element: <Protected />,
    loader: async () => {
      try{
        // logged in? pass user to the route
        const user = await account.get();
        return { user };
      }
      catch {
        // not logged in? redirect to login
        throw redirect('/login')
      }
    }
  },
  {
    path: "/login",
    element: <Login />,
  },
]);

export default router;
```
{% /tabsitem %}
{% tabsitem #vue-router title="Vue router" %}
You can use [Vue router](https://router.vuejs.org/) wiht a [Pinia store](https://pinia.vuejs.org/) to check if the user is logged in before rendering a route.

First, create a simple Pinia store to manage the authentication state.
```client-web
import { account, ID, type Models } from '@/lib/appwrite'
import type { register } from 'module';
import { defineStore } from 'pinia'

export const useAuthStore = defineStore({
  id: 'auth',
  state: () => ({
    user: null as null | Models.User<Models.Preferences>,
  }),
getters: {
    isLoggedIn(): boolean {
      return !!this.user;
    },
},
  actions: {
    async init() {
        try {
            this.user = await account.get();
        }
        catch (error) {
            this.user = null;
        }
    },
    // ... other operations
  },
})
```
Then, check the authentication state before routing to a protected route.
```client-web
import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { useAuthStore } from './stores/auth'

const app = createApp(App)
app.use(createPinia())

const auth = useAuthStore();

auth.init().then(() => {
  router.beforeEach((to, from, next) => {
    // Not logged in? 
    if (to.name == 'protected' && auth.isLoggedIn == false) {
    // Redirect to login if going to a protected route
      next({ name: 'login' })
    } else {
      next()
    }
  })
  app.use(router)
  app.mount('#app')
})
```
{% /tabsitem %}
{% tabsitem #angular-router title="Angular router" %}
```client-web
import { Injectable } from '@angular/core';
import { ActivatedRouteSnapshot, CanActivate, RouterStateSnapshot, UrlTree, Router } from '@angular/router';
import { Observable, from, of } from 'rxjs';
import { catchError, map } from 'rxjs/operators';
import { account } from './lib/appwrite';

@Injectable({
  providedIn: 'root'
})
export class AuthGuardGuard implements CanActivate {
  constructor(private router: Router) {}

  canActivate(
    route: ActivatedRouteSnapshot,
    state: RouterStateSnapshot): Observable<boolean | UrlTree> | Promise<boolean | UrlTree> | boolean | UrlTree {
    if (route.routeConfig?.path === "protected") {
      return this.checkLogin();
    }
    return of(true);
  }

  private checkLogin(): Observable<boolean | UrlTree> {
    return from(account.get()).pipe(
      map(() => true),
      catchError(() => of(this.router.createUrlTree(['/login'])))
    );
  }
}
```
{% /tabsitem %}
{% tabsitem #svelte title="Svelte" %}
In the root level `+layout.svelte` file, you can check the authentication state before rendering a route.
```client-web
// src/routes/+layout.js
import { appwrite } from "$lib/appwrite";

// Turn off SSR globally, turning the project into a static site
export const ssr = false;

export const load = async () => {
  try {
    return {
      account: await appwrite.account.get(),
    };
  } catch {
    return {
      account: null,
    };
  }
};
```

This will be accessible in the `load` function of each child route.
```client-web
// src/routes/protected/+page.js
import { redirect } from '@sveltejs/kit';

/** @type {import('./$types').PageLoad} */
export async function load({ parent }) {
	const { account } = await parent();
	if (!account) {
		throw redirect(303, '/login');
	}
}
```
{% /tabsitem %}
{% /tabs %}

{% /accordion_item %}
{% accordion_item title="Mobile and native" %}
With mobile apps, you can apply similar logic to check the authentication state before displaying a screen or view.

{% tabs %}
{% tabsitem #flutter-go-router title="Flutter Go router" %}
This example uses the Flutter Go router as an example, but the same concepts apply to other routing libraries.

First, create a `ChangeNotifier` to manage the authentication state.

```client-flutter
import 'package:flutter/material.dart';
import 'package:appwrite/appwrite.dart' show Client, ID;
import 'package:appwrite/appwrite.dart' as Appwrite;
import 'package:appwrite/models.dart' show User;

class Account extends ChangeNotifier {
  final Appwrite.Account _account;

  User? _user;
  User? get user => _user;

  Account(Client client) : _account = Appwrite.Account(client);

  Future<void> init() async {
    try {
      _user = await _account.get();
      
      notifyListeners();
    } catch(e) {
      debugPrint(e.toString());
      rethrow;
    }
  }
  // ... other operations
}
```

You can then use this state to redirect users to the login page if they are not authenticated.
```client-flutter
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import './providers/account.dart';

import './pages/login.dart';
import './pages/protected.dart';

String Function(BuildContext context, GoRouterState state) redirect = 
  (BuildContext context, GoRouterState state) => 
    context.read<Account>().user == null && state.matchedLocation != '/login'
    ? '/login' 
    : state.matchedLocation;

final router = GoRouter(
  redirect: redirect,
  initialLocation: '/login',
  routes: [
    GoRoute(
      path: '/login',
      pageBuilder: (context, state) => const MaterialPage(child: LoginPage()),
    ),
    GoRoute(
      path: '/protected',
      pageBuilder: (context, state) => const MaterialPage(child: ProtectedPage()),
    )
  ],  
);
```
{% /tabsitem %}

{% tabsitem #apple title="Apple" %}
For Apple platforms, this example uses a `NavigationStack` but you can use similar concepts with other navigation methods.

Initialize Appwrite and create an `AppwriteService`.
```client-apple
import Foundation
import Appwrite
import AppwriteModels
import JSONCodable

class Appwrite {
    var client: Client
    var account: Account
    var tablesDB: TablesDB
    let databaseId = "default"
    let tableId = "ideas-tracker"

    public init() {
        self.client = Client()
            .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
            .setProject("<PROJECT_ID>")
        
        self.account = Account(client)
    }
    public func getUser() async throws -> User<[String: AnyCodable]> {
        let user = try await account.get()
        // you can also store the user in a local store
        return user
    }
}

```

On launch, you can display a `SplashView` while you verify the authentication state.
```client-apple
import Foundation
import SwiftUI

struct SplashView: View {
    @EnvironmentObject private var router: Router
    @EnvironmentObject private var AppwriteService: AppwriteService

    var body: some View {
        NavigationStack(path: $router.routes) {
            VStack {
                Text("Example App")
                    .font(.largeTitle)
                    .fontWeight(.bold)
                    .padding()
            }.task {
                let user = await self.AppwriteService.getUser();

                if !user {
                    router.pushReplacement(.login)
                } else {
                    router.pushReplacement(.protected)
                }
            }
            .navigationDestination(for: Route.self, destination: { $0 })

        }
    }
}
```

In your router, you can also check the authentication state before rendering a route.

```client-apple
final class Router: ObservableObject {
    @Published var routes = [Route]()

    func push(_ screen: Route) {
        // Make sure you've already stored the user and auth state in a local store
        if (screen == .protected && !isLoggedIn){
            routes.append(.login)
        }
        routes.append(screen)
    }
    // ... other operations
}
```
{% /tabsitem %}
{% tabsitem #android title="Android" %}
Create some Appwrite Service, for example, `AppwriteService` to manage the authentication state.
You can find a version of this example in the [Appwrite Android tutorial](/docs/tutorials/android/step-1).
```client-android-kotlin
//... imports

object Appwrite {
    private const val ENDPOINT = "https://<REGION>.cloud.appwrite.io/v1"
    private const val PROJECT_ID = "<PROJECT_ID>"

    private lateinit var client: Client

    fun init(context: Context) {
        client = Client(context)
            .setEndpoint(ENDPOINT)
            .setProject(PROJECT_ID)
    }
}

```

Then, create an auth service to manage the authentication state.

```client-android-kotlin
//... imports

class AccountService(client: Client) {
    private val account = Account(client)

    suspend fun getLoggedIn(): User<Map<String, Any>>? {
        return try {
            account.get()
        } catch (e: AppwriteException) {
            null
        }
    }   
    // ... other operations
}
```

Wrap your routes in some view, for example, `AppContent`, to check the authentication state before rendering a route.

```client-android-kotlin
@Composable
private fun AppContent(accountService: AccountService) {
    val user = remember { mutableStateOf<User<Map<String, Any>>?>(null) }
    val screen = remember { mutableStateOf(Screen.Protected) }

    LaunchedEffect(screen) {
        user.value = accountService.getLoggedIn()
    }

    Scaffold(bottomBar = { AppBottomBar(screen) }) { padding ->
        Column(modifier = Modifier.padding(padding)) {
            when (screen.value) {
                Screen.User -> LoginScreen(user, accountService)
                else -> ProtectedScreen(user.value)
            }
        }
    }
}
```

In the `MainActivity` class, initialize the Appwrite service and display the `AppContent` based on the authentication state.

```client-android-kotlin
// ...imports 
In the `MainActivity` class, initialize the Appwrite service.
class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        Appwrite.init(applicationContext)

        setContent {
            // Update this line 👇
            AppContent(Appwrite.account)
        }
    }
}
```
{% /tabsitem %}
{% tabsitem #react-native title="React Native" %}
This example will use `@react-navigation/native` and `@react-navigation/native-stack` to manage the authentication state and redirect users to the login page if they are not authenticated.
You can find a version of this example in the [Appwrite Android tutorial](/docs/tutorials/android/step-1).

Create a `UserContext` to manage the authentication state.
```client-react-native
import { StyleSheet, Text, View } from 'react-native';
import { UserProvider } from './contexts/UserContext';
import { Router } from './lib/Router';

export default function App() {
  return (
    <UserProvider>
        <Router />
    </UserProvider >
  );
}
```

Then, consume the `UserContext` in your `Router` component to check the authentication state before rendering a route.
```client-react-native
import { NavigationContainer } from '@react-navigation/native';
import LoginScreen from '../views/Login';
import ProtectedSCreen from '../views/Protected';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { useUser } from '../contexts/UserContext';

const Stack = createNativeStackNavigator();
export function Router() {
    const user = useUser();
    return (
        <NavigationContainer>
            <Stack.Navigator>
                {user.current == null ? (
                    <Stack.Screen
                        name="Login"
                        component={LoginScreen}
                        options={{ title: 'Login' }}
                    />
                ) : (
                    <Stack.Screen
                        name="Protected"
                        component={ProtectedSCreen}
                        options={{ title: 'Protected' }}
                    />
                )}

            </Stack.Navigator>
        </NavigationContainer>
    );
}
```
{% /tabsitem %}
{% /tabs %}
{% /accordion_item %}
{% /accordion %}
{% /section %}
