## Add Appwrite Auth to a New Angular App

Goal: Add Appwrite auth to a new Angular app with a working login/register/logout page.

Do exactly these steps in order. Confirm each step succeeds before continuing. If any command fails, show the error and fix it automatically.

Respect user's package manager at all times. Don't use NPM if the user uses something else.

## Step 1: Create or Use Existing Angular App

- First, check the current working directory:
    - If the directory contains files that appear unrelated to a development workspace (e.g., personal files, downloads, random documents, media files), ask the user: 'The current directory contains files that don't appear to be part of a development project. Would you like to: (1) proceed here anyway, or (2) create a subdirectory with a specific folder name?' and proceed based on their choice.
    - If the directory is empty OR contains an existing project (`package.json`, `src` folder, config files, etc.), proceed with integration without asking.
- If an existing Angular project is detected, use it directly.
- Otherwise, ensure Angular CLI is installed: `npm install -g @angular/cli`
- Create a project in the current directory: `ng new . --directory .`
- NEVER use `cd` to change directories - always work in the current directory (`.`)

## Step 2: Install Appwrite SDK

- Run: `npm install appwrite`

## Step 3: Create Appwrite Client Module

Ask the user for details; never assume.

- Ask the user for:
    - **Appwrite Cloud Region** (e.g. `fra`, `nyc`)
    - **Project ID** (from Console -> Settings)
- Hardcode the endpoint and **Project ID** in the file `src/lib/appwrite.ts` if provided, else leave placeholder and ask the user to provide them.
- Create file `src/lib/appwrite.ts` with key snippet:

```ts
import { Client, Account } from 'appwrite';

export const client = new Client();

client.setEndpoint('https://<REGION>.cloud.appwrite.io/v1').setProject('<PROJECT_ID>'); // Replace with your project ID

export const account = new Account(client);
export { ID } from 'appwrite';
```

## Step 4: Build the Login Page

- First, add `FormsModule` import to handle the login form. Update `app.module.ts`:

```ts
import { FormsModule } from '@angular/forms';
...
@NgModule({
  declarations: [
    // ...
  ],
  imports: [
    // ...
    FormsModule
  ],
  providers: [],
  bootstrap: [AppComponent]
})
export class AppModule { }
```

- Replace the contents of `src/app/app.component.html`:

```html
<div>
    <p>{{ loggedInUser ? 'Logged in as ' + loggedInUser.name : 'Not logged in' }}</p>

    <div>
        <input type="email" placeholder="Email" [(ngModel)]="email" />
        <input type="password" placeholder="Password" [(ngModel)]="password" />
        <input type="text" placeholder="Name" [(ngModel)]="name" />

        <button (click)="login(email, password)">Login</button>

        <button (click)="register(email, password, name)">Register</button>

        <button (click)="logout()">Logout</button>
    </div>
</div>
```

- Update `src/app/app.component.ts`:

```ts
import { Component } from '@angular/core';
import { account, ID } from '../lib/appwrite';
@Component({
    selector: 'app-root',
    templateUrl: './app.component.html',
    styleUrls: ['./app.component.css']
})
export class AppComponent {
    loggedInUser: any = null;
    email: string = '';
    password: string = '';
    name: string = '';

    async login(email: string, password: string) {
        await account.createEmailPasswordSession({
            email,
            password
        });
        this.loggedInUser = await account.get();
    }

    async register(email: string, password: string, name: string) {
        await account.create({
            userId: ID.unique(),
            email,
            password,
            name
        });
        this.login(email, password);
    }

    async logout() {
        await account.deleteSession({
            sessionId: 'current'
        });
        this.loggedInUser = null;
    }
}
```

## Step 5: Verify Environment

Ask the user to confirm.

- Confirm endpoint and **Project ID** are set in `src/lib/appwrite.ts`.
- Ensure the Web app platform exists in Appwrite Console with **Hostname** = `localhost`. If missing, guide the user to add it.

## Step 6: Run and Test

- Run: `ng serve --port 3000`
- Open: `http://localhost:3000`
- Test flows:
    - Register a new user and auto login works
    - Logout then login again
- Surface any Appwrite errors (invalid project, endpoint, CORS/hostname) and fix by guiding updates to `appwrite.ts` and Console settings.

## Deliverables

- A running Angular app with working Appwrite auth (register/login/logout)
- Files created/updated: `package.json` (deps), `src/lib/appwrite.ts`, `src/app/app.module.ts`, `src/app/app.component.html`, `src/app/app.component.ts`
