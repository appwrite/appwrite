---
layout: tutorial
title: Add authentication
description: Add authentication to your Refine application.
step: 4
---

## Authentication provider {% #authentication-provider %}


Upon [creating a new project](https://refine.dev/docs/getting-started/quickstart/) with Appwrite preset, the CLI automatically creates [Auth Provider](https://refine.dev/docs/tutorial/understanding-authprovider/index/#what-is-auth-provider) file.


You'll see a file named [`src/authProvider.ts`](https://github.com/refinedev/refine/blob/master/examples/data-provider-appwrite-tutorial-docs/src/utility/authProvider.ts) created by CLI. This auto-generated file contains pre-defined functions using Appwrite Authentication methods internally to perform authentication and authorization operations.


The auth provider registered to the Refine app by default in the `src/App.tsx`.
```ts 

import { authProvider } from './authProvider';
...

 <Refine
    ...
    authProvider={authProvider}
>
```

Now, we can configure the routing and auth components to manage logins and sign ups.


##  Routing  {% #routing %} 

Refine offers router bindings and utilities for [React Router v6](https://reactrouter.com/en/main) and improves the user interface with the [routerProvider](https://refine.dev/docs/api-reference/core/components/refine-config/#routerprovider) prop. 
This allows the framework to identify resources from routes and efficiently handle query parameters and navigation tasks.


```ts
import routerProvider from '@refinedev/react-router-v6';
import { BrowserRouter } from 'react-router-dom';
import { authProvider } from './authProvider';
...

<BrowserRouter>
    <Refine
        ...
        authProvider={authProvider}
        routerProvider={routerProvider}
    >
    ...
</BrowserRouter>
```

## Login page {% #login-page %}




We'll use [Routes](https://reactrouter.com/en/main/components/routes) component to connect routing mechanisim along with [AuthPage](https://refine.dev/docs/api-reference/antd/components/antd-auth-page/#usage) component which returns ready-to-use authentication pages for login, register, update, and forgot password actions.

Update `src/App.tsx` to the following code.

```ts
import { Authenticated, Refine } from "@refinedev/core";
import { dataProvider, liveProvider } from "@refinedev/appwrite";
import {
  AuthPage,
  ErrorComponent,
  RefineThemes,
  ThemedLayoutV2,
  useNotificationProvider,
} from "@refinedev/antd";
import routerProvider, {
  CatchAllNavigate,
  NavigateToResource,
} from "@refinedev/react-router-v6";
import "@refinedev/antd/dist/reset.css";

import { App as AntdApp, ConfigProvider } from "antd";
import { BrowserRouter, Outlet, Route, Routes } from "react-router-dom";

import { appwriteClient } from "./utility";
import { authProvider } from "./authProvider";

const App: React.FC = () => (
  <BrowserRouter>
    <ConfigProvider theme={RefineThemes.Blue}>
      <AntdApp>
        <Refine
          dataProvider={dataProvider(appwriteClient, {
            databaseId: "<APPWRITE_DATABASE_ID>",
          })}
          liveProvider={liveProvider(appwriteClient, {
            databaseId: "<APPWRITE_DATABASE_ID>",
          })}
          authProvider={authProvider}
          routerProvider={routerProvider}
          notificationProvider={useNotificationProvider}
        >
          <Routes>
            <Route
              element={
                <Authenticated 
                  fallback={<CatchAllNavigate to="/login" />}
                >
                  <ThemedLayoutV2>
                    <Outlet />
                  </ThemedLayoutV2>
                </Authenticated>
              }
            ></Route>

            <Route
              element={
                <Authenticated fallback={<Outlet />}>
                  <NavigateToResource 
                    resource="<APPWRITE_TABLE_ID>" 
                  />
                </Authenticated>
              }
            >
              <Route
                path="/login"
                element={<AuthPage forgotPasswordLink={false} />}
              />
              <Route path="/register" 
               element={<AuthPage type="register" />} 
              />
            </Route>

            <Route
              element={
                <Authenticated>
                  <ThemedLayoutV2>
                    <Outlet />
                  </ThemedLayoutV2>
                </Authenticated>
              }
            >
              <Route path="*" element={<ErrorComponent />} />
            </Route>
          </Routes>
        </Refine>
      </AntdApp>
    </ConfigProvider>
  </BrowserRouter>
);

export default App;
```
Key concepts to handle authentication and routing:

- The [`<AuthPage>`](https://refine.dev/docs/api-reference/antd/components/antd-auth-page) component in Refine includes pages for login, registration, password reset, and password update functionalities.
- To manage authenticated routes effectively, the [`<Authenticated>`](https://refine.dev/docs/api-reference/core/components/auth/authenticated/) component using to determine the user's authentication status and accordingly directs them or displays relevant elements.
- Within the `<Authenticated>` component, we use the `<Outlet>` component from `react-router-dom` to render secure routes that are accessible only to authenticated users.
- We set up a `/login` route for redirecting unauthenticated users, using Refine's AuthPage components with a `type="login"` prop to create the login page efficiently.

When you refresh the page, the login screen appears.

![Refine login screen](/images/docs/tutorials/refine/refine-login-page.avif)


We'll activate the authentication mechanisim with Appwrite in the next section.


