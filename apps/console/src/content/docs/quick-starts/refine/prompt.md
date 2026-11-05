## Add Appwrite to a new Refine app with a working login/register page using the Refine-Appwrite preset

Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.

Respect user's package manager at all time. Don't use NPM if the user uses something else.

## Step 1: Create Appwrite project

- Guide the user to head to the [Appwrite Console](https://cloud.appwrite.io/console).
- If this is their first time using Appwrite, create an account and create their first project.
- Under **Add a platform**, add a **Web app** with **Hostname** = `localhost`.
- Optional steps can be skipped.

## Step 2: Create Refine project

- Before scaffolding, check if the current working directory contains files that appear unrelated to a development workspace (e.g., personal files, downloads, random documents, media files). If so, ask the user: 'The current directory contains files that don't appear to be part of a development project. Would you like to: (1) proceed here anyway, or (2) create it in a subdirectory with a specific folder name?' and proceed based on their choice.
- If the directory is empty OR contains an existing project (e.g., `package.json`, `src` folder, config files), proceed with integration without asking.
- Run: `npm create refine-app@latest . -- --preset refine-appwrite`
- Create the project in the current working directory (`.`) - do NOT use `cd` to switch directories.
- This preset includes Appwrite support out of the box.

## Step 3: Install Appwrite (for existing projects only)

- Using the `refine-appwrite` preset eliminates the need for extra dependencies for a quick start.
- If integrating into an existing Refine app, run: `npm install @refinedev/appwrite`
- Then follow the Refine documentation: https://refine.dev/docs/packages/documentation/data-providers/appwrite

## Step 4: Configure Appwrite client (ask user for details; never assume)

- Ask the user for:
    - **Appwrite API Endpoint** (e.g. `https://cloud.appwrite.io/v1`)
    - **Project ID** (from Console -> Settings)
- Navigate to `src/utility/appwriteClient.ts` and add API credentials:

```ts
import { Account, Appwrite, Storage } from '@refinedev/appwrite';

const APPWRITE_URL = '<YOUR_API_ENDPOINT>'; // Replace with your Appwrite API Endpoint
const APPWRITE_PROJECT = '<PROJECT_ID>'; // Replace with your project ID

const appwriteClient = new Appwrite();

appwriteClient.setEndpoint(APPWRITE_URL).setProject(APPWRITE_PROJECT);
const account = new Account(appwriteClient);
const storage = new Storage(appwriteClient);

export { account, appwriteClient, storage };
```

## Step 5: Create a login page

- Replace the code in `src/App.tsx` with the following:

```tsx
import { Authenticated, Refine } from '@refinedev/core';
import { dataProvider, liveProvider } from '@refinedev/appwrite';
import {
    AuthPage,
    ErrorComponent,
    RefineThemes,
    ThemedLayoutV2,
    useNotificationProvider
} from '@refinedev/antd';
import routerProvider, { CatchAllNavigate } from '@refinedev/react-router-v6';
import '@refinedev/antd/dist/reset.css';

import { App as AntdApp, ConfigProvider } from 'antd';
import { BrowserRouter, Navigate, Outlet, Route, Routes } from 'react-router-dom';

import { appwriteClient } from './utility';
import { authProvider } from './authProvider';

const App: React.FC = () => {
    return (
        <BrowserRouter>
            <ConfigProvider theme={RefineThemes.Blue}>
                <AntdApp>
                    <Refine
                        dataProvider={dataProvider(appwriteClient, {
                            databaseId: '<APPWRITE_DATABASE_ID>'
                        })}
                        liveProvider={liveProvider(appwriteClient, {
                            databaseId: '<APPWRITE_DATABASE_ID>'
                        })}
                        authProvider={authProvider}
                        routerProvider={routerProvider}
                        notificationProvider={useNotificationProvider}
                    >
                        <Routes>
                            <Route
                                element={
                                    <Authenticated fallback={<CatchAllNavigate to="/login" />}>
                                        <ThemedLayoutV2>
                                            <Outlet />
                                        </ThemedLayoutV2>
                                    </Authenticated>
                                }
                            ></Route>

                            <Route
                                element={
                                    <Authenticated fallback={<Outlet />}>
                                        <Navigate to="/" />
                                    </Authenticated>
                                }
                            >
                                <Route path="/login" element={<AuthPage />} />
                                <Route path="/register" element={<AuthPage type="register" />} />
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
};

export default App;
```

- Ask the user for their **Appwrite Database ID** to replace the `<APPWRITE_DATABASE_ID>` placeholder.

## Step 6: Run and test

- Run: `npm run dev -- --open --port 3000`
- Open: `http://localhost:3000`
- Test flows:
    - Navigate to `/login` page
    - Navigate to `/register` page to create a new user
- Surface any Appwrite errors (invalid project, endpoint, CORS/hostname) and fix by guiding updates to `appwriteClient.ts` and Console settings.

## Deliverables

- A running Refine app with Appwrite integration and login/register pages
- Files created/updated: `package.json` (deps), `src/utility/appwriteClient.ts`, `src/App.tsx`
