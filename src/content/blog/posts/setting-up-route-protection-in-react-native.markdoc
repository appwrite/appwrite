---
layout: post
title: "Setting up route protection in React Native"
description: Learn how to set up route protection in a React Native application using Appwrite.
date: 2025-02-08
cover: /images/blog/setting-up-route-protection-in-react-native/cover.avif
timeToRead: 4
author: nishant-jain
category: tutorial
featured: false
callToAction: true
faqs:
  - question: "How do I structure protected and public routes in Expo Router?"
    answer: "Group protected routes inside a folder like `(app)` and keep public routes (sign-in, sign-up) outside it. The route group gets its own `_layout.tsx` that performs the auth check and redirects to the sign-in screen if there is no active session, so the protection lives in one place instead of every screen."
  - question: "Where should the auth check live?"
    answer: "Inside the protected group's `_layout.tsx`. Read the session from your auth context and either render the `Slot` (when authenticated) or `Redirect` to the sign-in screen (when not). This runs before any child route mounts, so unauthenticated users never see protected content."
  - question: "How do I share auth state across screens in React Native?"
    answer: "Use React Context. Create an `AuthProvider` that exposes user, session, `signIn`, and `signOut`, wrap your root layout with it, and consume the context via a `useAuth` hook from any screen. This keeps the session in one source of truth and avoids prop drilling."
  - question: "How do I handle the loading state while restoring a session?"
    answer: "Track a `loading` flag in your auth provider that is true until you have finished checking for an existing session on app start. Render a splash or loader while loading is true so users do not see a flicker between public and protected routes during cold start."
  - question: "How does Appwrite fit into this flow?"
    answer: "[Appwrite Auth](/docs/products/auth) manages users and sessions on the backend. Your React Native app calls Appwrite to create or restore a session, and you set that session in your auth context. The route protection logic stays the same, only the `signIn`, `signOut`, and session-restore methods talk to Appwrite."
  - question: "What happens to deep links when a user is not authenticated?"
    answer: "Because the route group's layout intercepts any unauthenticated access and redirects to sign-in, deep links into protected routes will also bounce to the sign-in screen. To send users back to the original deep link after login, capture the intended path before redirecting and navigate to it once a session is established."
---

In this guide, we'll walk through implementing protected routes in React Native using a simplified authentication approach with hardcoded values. The implementation uses a straightforward application structure that's easy to follow along with. 

While you can start from scratch, we recommend having a basic React Native project already set up. If you need help with the initial setup, refer to our React Native Appwrite SDK Tutorial below:
{% youtube src="https://www.youtube-nocookie.com/embed/YSUmzHH_OMg?si=EWm7gnPUASMKygrr" thumbnail="/images/blog/setting-up-route-protection-in-react-native/thumbnail-1.avif" /%}


# Prerequisites

Before we begin, you should have:
- A React Native project with Appwrite integration - Follow our [quick start guide](/docs/quick-starts/react-native) to set it up
- Basic understanding of React Native and navigation concepts


# Step 1: Setting up the file structure

First, we'll separate our routes into two categories: protected (which requires authentication) and public. We'll create this separation by wrapping our protected routes in a folder called (app):

```
src
├── app
│   ├── _layout.tsx    // Root layout
│   └── signin.tsx     // Public route
│
└── (app)           // Protected routes group
    ├── _layout.tsx    // Protected layout
    └── index.tsx      // Protected home screen
```

To protect the routes in our protected route group, we'll add an auth check within the protected route group's `_layout.tsx` file. For now, we'll use a hardcoded session value to keep things simple:

```typeScript
// app/(app)/_layout.tsx
import { Slot, Redirect} from "expo-router";

export default function AppLayout() {
  const session = false

  return !session ? <Redirect href="/signin"/> : <Slot/>
}
```
This is as simple as it gets - we're just blocking users from accessing any route that uses this layout if there's no session. However, in a real scenario, we want to pull the user state from some sort of provider, which we'll set up next.

# Step 2: Creating our auth context

Let's create our auth context that we'll use throughout the app:

```typescript
// app/context/AuthContext.js
import { useContext, createContext, useState, useEffect } from 'react';
import { Text, SafeAreaView } from 'react-native';

const AuthContext = createContext()

const AuthProvider = ({children}) => {
    const [user, setUser] = useState(false)
    const [session, setSession] = useState(false)
    const [loading, setLoading] = useState(true)

    const signIn = async () => {}
    const signOut = async () => {}

   const contextData = {
       user,
       session,
       signIn,
       signOut
   }

  return (
         <AuthContext.Provider value={contextData}>
            {loading ? <SafeAreaView>
                    <Text>Loading...</Text>
                </SafeAreaView> : children}
          </AuthContext.Provider>
         )
}

export {AuthContext, AuthProvider}

const useAuth = () => {return useContext(AuthContext)}

export {useAuth}
```

# Step 3: Setting up the root layout

To use our auth context, we'll wrap the `root` layout with the `AuthProvider`:

```typescript
import { Slot } from 'expo-router';
import { AuthProvider } from './context/AuthContext.js';

export default function Root() {
  return (
    <AuthProvider>
      <Slot />
    </AuthProvider>
  );
}
```

# Step 4: Implementing protected routes

Now let's create our protected layout that will guard our private routes:

```typescript
// app/(app)/_layout.tsx
import { Slot, Redirect} from "expo-router";
import {useAuth} from '../context/AuthContext.js'

export default function AppLayout() {
  const {user, session} = useAuth()

  return !session ?  <Redirect href="/signin"/> : <Slot/>
}
```

# Step 5: Setting up the sign-in page

Finally, let's handle the case where an authenticated user tries to visit the sign-in page:

```typeScript
// app/signin.tsx
import { Redirect } from 'expo-router'
import { useAuth } from '@/context/AuthContext'
import { SafeAreaView, Text } from 'react-native'

const signin = () => {
  const {session} = useAuth()

  if (session) return <Redirect href="/"/>

  return (
    <SafeAreaView>
      <Text>signin</Text>
    </SafeAreaView>
  )
}

export default signin
```
This completes our implementation of protected routes in React Native. You now have a foundation for managing authenticated and public routes using React Context to handle the global session state.

Now that you have a basic route protection system in place, you can enhance it by implementing actual authentication logic using Appwrite. If you run into any issues or have questions, the Appwrite community on [Discord](https://discord.com/invite/appwrite) is always ready to help.

# More resources

If you would like to learn more about React Native and Appwrite, we have some resources that you should visit:

- [Getting started with React Native and Appwrite](https://appwrite.io/docs/quick-starts/react-native)
- [Authentication with Appwrite](https://appwrite.io/docs/products/auth/quick-start)
- [React Native Appwrite starter project](https://github.com/divanov11/react-native-appwrite/tree/getting_started)
- [Quick start with React Native](https://reactnative.dev/docs/environment-setup)
- [Appwrite Account API reference](https://appwrite.io/docs/references/cloud/client-react-native/account)