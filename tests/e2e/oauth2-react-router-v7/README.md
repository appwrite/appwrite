# OAuth2 React Router v7 End-to-End Test

This is an end-to-end test application to demonstrate the fix for the 401 Unauthorized error that occurs after Google OAuth sign-in with Appwrite when using React Router v7.

## Purpose

This test is located in `tests/e2e/oauth2-react-router-v7/` as it's an integration test that verifies the complete OAuth2 authentication flow works properly with React Router v7 after the fix for issue #10207.

## Issue Being Fixed

**Bug Report #10207**: After completing Google OAuth login with Appwrite Cloud, the session was not properly established. All subsequent calls like `account.get()` or `account.getPrefs()` failed with a 401 Unauthorized error, even though the user was redirected back successfully.

Error message:
```json
{
  "message": "User (role: guests) missing scope (account)",
  "code": 401,
  "type": "general_unauthorized_scope",
  "version": "1.7.4"
}
```

## Setup Instructions

### 1. Install Dependencies
```bash
cd tests/e2e/oauth2-react-router-v7
npm install
```

### 2. Configure Appwrite Project

1. **Create/Configure Appwrite Project:**
   - Go to [Appwrite Cloud Console](https://cloud.appwrite.io)
   - Create a new project or use existing one
   - Copy your Project ID

2. **Set up Google OAuth2:**
   - Go to Project Settings → Auth → OAuth2 Providers
   - Enable Google provider
   - Add your Google OAuth2 credentials (Client ID, Client Secret)
   - Set authorized redirect URIs to include:
     - `http://localhost:3000/auth/success`
     - `http://localhost:3000/auth/failure`

3. **Configure Project Settings:**
   - Go to Project Settings → General
   - Add `http://localhost:3000` to allowed origins
   - Ensure your domain is properly configured

4. **Update Configuration:**
   - Edit `src/App.tsx`
   - Replace `'YOUR_PROJECT_ID'` with your actual Appwrite project ID:
   ```typescript
   const client = new Client()
     .setEndpoint('https://cloud.appwrite.io/v1')
     .setProject('your-actual-project-id') // Replace this
   ```

### 3. Run the Application
```bash
npm run dev
```

The application will start at `http://localhost:3000`

## Testing the Fix

### Test Scenario
1. **Open the application** at `http://localhost:3000`
2. **Click "Login with Google"** button
3. **Complete OAuth2 authentication** on Google
4. **Verify successful redirect** back to the application
5. **Check if `account.get()` works** without 401 errors
6. **View user data** on the dashboard if successful

### Expected Behavior (After Fix)
- ✅ OAuth2 redirect completes successfully
- ✅ Session is established immediately after redirect
- ✅ `account.get()` returns user data without 401 errors
- ✅ User sees dashboard with their information

### Previous Behavior (Before Fix)
- ❌ OAuth2 redirect completed but session wasn't established
- ❌ `account.get()` returned 401 Unauthorized
- ❌ User was treated as guest despite successful OAuth2

## How the Fix Works

### Server-Side Changes

1. **Enhanced OAuth2 Cookie Handling** (`app/controllers/api/account.php`):
   - Uses `SameSite=Lax` instead of `SameSite=None` for OAuth2 redirects
   - Improves compatibility with modern browsers and SPA frameworks like React Router v7
   - Maintains fallback cookie mechanisms

2. **Improved Client-Side Handling** (`public/sdk-web/client.ts`):
   - Enhanced fallback cookie detection for OAuth2 flows
   - Better session establishment for account-related requests

### What Changed

**Before:** OAuth2 sessions used `SameSite=None` cookies which:
- Required HTTPS in production
- Were blocked by some browsers in development
- Caused compatibility issues with React Router v7's navigation

**After:** OAuth2 sessions use `SameSite=Lax` cookies which:
- Work in both HTTP (development) and HTTPS (production)
- Are compatible with modern browsers and SPA frameworks
- Maintain security while improving compatibility

## Troubleshooting

### If you still see 401 errors:

1. **Check browser console** for any cookie-related warnings
2. **Verify Appwrite configuration** (project ID, OAuth2 credentials, allowed origins)
3. **Test in different browsers** to rule out browser-specific issues
4. **Check network tab** to see if cookies are being set properly

### Common Issues:

- **Invalid Project ID**: Make sure you replaced `'YOUR_PROJECT_ID'` with your actual project ID
- **OAuth2 Not Configured**: Ensure Google OAuth2 is properly set up in Appwrite console
- **Origin Not Allowed**: Add `http://localhost:3000` to allowed origins in project settings

## Files Structure

```
tests/e2e/oauth2-react-router-v7/
├── package.json          # Dependencies and scripts
├── vite.config.ts        # Vite configuration
├── tsconfig.json         # TypeScript configuration
├── index.html            # HTML entry point
├── src/
│   ├── main.tsx          # React app entry point
│   ├── App.tsx           # Main application with OAuth2 test
│   └── index.css         # Basic styles
└── README.md             # This file
```

## Video Recording Commands

To record a demo video showing the fix working:

1. **Navigate to the test directory:**
   ```bash
   cd tests/e2e/oauth2-react-router-v7
   ```

2. **Install dependencies:**
   ```bash
   npm install
   ```

3. **Start the application:**
   ```bash
   npm run dev
   ```

2. **Open browser** to `http://localhost:3000`

3. **Record the following steps:**
   - Show the login page
   - Click "Login with Google"
   - Complete OAuth2 authentication
   - Show successful redirect and immediate session establishment
   - Display user data on dashboard

This demonstrates that the OAuth2 + React Router v7 + Appwrite integration now works without 401 errors.

## Technical Details

- **React Router**: v7.0.0
- **Appwrite SDK**: v16.0.2
- **React**: v18.3.1
- **TypeScript**: v5.5.3
- **Build Tool**: Vite v5.4.1

The test application uses the latest versions of React Router v7 and Appwrite SDK to demonstrate that the fix resolves the reported issue.