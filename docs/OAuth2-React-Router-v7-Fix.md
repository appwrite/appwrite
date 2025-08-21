# OAuth2 Authentication with React Router v7 - Issue Fix

## Problem Description

Users experiencing 401 Unauthorized errors after Google OAuth sign-in when using React Router v7 with Appwrite. The error occurs because session cookies are not properly established after OAuth2 redirect.

Error message:
```json
{
  "message": "User (role: guests) missing scope (account)",
  "code": 401,
  "type": "general_unauthorized_scope",
  "version": "1.7.4"
}
```

## Root Cause

The issue is caused by:

1. **SameSite Cookie Attribute**: Cookies set with `SameSite=None` requiring HTTPS in production
2. **Cookie Fallback Mechanism**: X-Fallback-Cookies header not always being set
3. **React Router v7 Navigation**: Stricter cookie handling during client-side navigation

## Server-Side Fix Applied

### 1. OAuth2 Session Cookie Handling (`app/controllers/api/account.php`)

Updated the OAuth2 session creation to:
- Always set fallback cookies for OAuth2 sessions regardless of domain verification status
- Use `SameSite=Lax` instead of `SameSite=None` for OAuth2 redirects to improve compatibility
- Ensure both legacy and modern cookies are set properly

### 2. Web SDK Improvements (`public/sdk-web/client.ts`)

Enhanced the client to:
- Always include fallback cookies from localStorage for account-related requests
- Better handling of OAuth2 session establishment

## Client-Side Workarounds

### For React Router v7 Applications

If you're still experiencing issues after the server-side fix, implement this workaround:

```javascript
// After OAuth2 redirect, in your success callback
const handleOAuth2Success = async () => {
  try {
    // Wait a brief moment for cookies to be set
    await new Promise(resolve => setTimeout(resolve, 100));
    
    // Try to get the account
    const account = await appwrite.account.get();
    console.log('Authentication successful:', account);
    
    // Redirect or update your app state
    navigate('/dashboard');
  } catch (error) {
    if (error.code === 401) {
      // If 401, try again after a longer delay
      await new Promise(resolve => setTimeout(resolve, 1000));
      try {
        const account = await appwrite.account.get();
        console.log('Authentication successful on retry:', account);
        navigate('/dashboard');
      } catch (retryError) {
        console.error('Authentication failed:', retryError);
        // Redirect to login or show error
        navigate('/login');
      }
    } else {
      console.error('Authentication error:', error);
      navigate('/login');
    }
  }
};
```

### Alternative: Use Page Refresh

For simpler implementations, you can force a page refresh after OAuth2 redirect:

```javascript
// In your OAuth2 success URL handler
useEffect(() => {
  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('oauth_success')) {
    // Clear the URL parameter and refresh
    window.history.replaceState({}, '', window.location.pathname);
    window.location.reload();
  }
}, []);
```

### Environment-Specific Considerations

#### Development (HTTP)
- Ensure your Appwrite instance is configured to allow HTTP origins
- Use `SameSite=Lax` cookies (which is now applied automatically for OAuth2)

#### Production (HTTPS)
- Ensure your domain is properly configured in Appwrite console
- Use proper SSL certificates
- The fix should work seamlessly with `SameSite=Lax` cookies

## Testing the Fix

1. **Set up OAuth2 provider** (Google) in Appwrite console
2. **Implement OAuth2 login** in your React Router v7 app
3. **Test the flow**:
   - Click login with Google
   - Complete OAuth2 flow
   - Verify you're redirected back to your app
   - Check that `account.get()` returns user data without 401 error

## Additional Notes

- This fix maintains backward compatibility with existing implementations
- The fallback cookie mechanism ensures session persistence across different browser configurations
- Using `SameSite=Lax` provides better security while maintaining compatibility with modern SPA routing

## Support

If you continue to experience issues after applying this fix:

1. Check browser developer tools for cookie-related warnings
2. Verify your Appwrite project configuration
3. Test with different browsers to rule out browser-specific issues
4. Consider implementing the client-side workaround as a temporary solution
