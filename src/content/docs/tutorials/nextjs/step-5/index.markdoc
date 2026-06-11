---
layout: tutorial
title: Add navigation
description: Add navigation to your app.
step: 5
---

To help our users navigate the app we want it to have a navigation bar that's visible on all pages.
We will use the `useAuth()` hook for information about the current user.

With this piece of information we will show a login button when no user is logged in and a logout button when one is.
We will also put the user's email address next to the logout button.

Create a new file `src/components/Navbar.tsx` and add the code below.

```tsx
// src/components/Navbar.tsx
'use client';

import Link from 'next/link';
import { useAuth } from '../hooks/useAuth';

export default function Navbar() {
    const { current, logout } = useAuth();

    return (
        <nav className="main-header u-padding-inline-end-0">
            <h3 className="u-stretch eyebrow-heading-1">Idea Tracker</h3>
            {current ? (
                <div className="main-header-end u-margin-inline-end-16">
                    <p>{current.providerUid}</p>
                    <button 
                        className="button" 
                        type="button" 
                        onClick={logout}
                    >
                        Logout
                    </button>
                </div>
            ) : (
                <Link 
                    href="/login" 
                    className="button u-margin-inline-end-16"
                >
                    Login
                </Link>
            )}
        </nav>
    );
}
```

Now we need to add the navigation bar to our app layout. Update `src/app/layout.tsx` to include the navbar.

```tsx
// src/app/layout.tsx
import type { Metadata } from 'next';
import { Inter } from 'next/font/google';
import './globals.css';
import Navbar from '../components/Navbar';

const inter = Inter({ subsets: ['latin'] });

export const metadata: Metadata = {
    title: 'Ideas Tracker',
    description: 'Track your side project ideas',
};

export default function RootLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    return (
        <html lang="en">
            <body className={inter.className}>
                <Navbar />
                {children}
            </body>
        </html>
    );
}
```

Have a look in the browser at both the main page and the login page to test the new functionality.