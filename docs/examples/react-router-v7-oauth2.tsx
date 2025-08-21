import React, { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router';
import { Client, Account } from 'appwrite';

// Initialize Appwrite client
const client = new Client()
  .setEndpoint('https://cloud.appwrite.io/v1') // Your Appwrite endpoint
  .setProject('your-project-id'); // Your project ID

const account = new Account(client);

/**
 * React Router v7 OAuth2 Authentication Handler
 * 
 * This component demonstrates the proper way to handle OAuth2 authentication
 * with Appwrite in React Router v7 to avoid 401 unauthorized errors.
 */
export function OAuth2Handler() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  // Handle OAuth2 login initiation
  const handleGoogleLogin = () => {
    try {
      // Set success and failure URLs
      const successUrl = `${window.location.origin}/auth/oauth2/success`;
      const failureUrl = `${window.location.origin}/auth/oauth2/failure`;
      
      // Initiate OAuth2 session with Google
      account.createOAuth2Session(
        'google',
        successUrl,
        failureUrl
      );
    } catch (error) {
      console.error('OAuth2 login error:', error);
      setError('Failed to initiate login');
    }
  };

  // Handle OAuth2 success with proper session verification
  const handleOAuth2Success = async (retryCount = 0) => {
    const maxRetries = 3;
    const retryDelay = retryCount === 0 ? 100 : 1000; // Quick first attempt, longer delays for retries

    try {
      setLoading(true);
      setError(null);

      // Wait for cookies to be properly set
      await new Promise(resolve => setTimeout(resolve, retryDelay));

      // Attempt to get user account
      const accountData = await account.get();
      
      console.log('OAuth2 authentication successful:', accountData);
      setUser(accountData);
      
      // Clear OAuth2 parameters from URL
      navigate('/dashboard', { replace: true });
      
    } catch (error) {
      console.error(`OAuth2 verification attempt ${retryCount + 1} failed:`, error);
      
      if (error.code === 401 && retryCount < maxRetries) {
        console.log(`Retrying OAuth2 verification (${retryCount + 1}/${maxRetries})...`);
        // Retry with exponential backoff
        setTimeout(() => {
          handleOAuth2Success(retryCount + 1);
        }, retryDelay);
      } else {
        setError('Authentication failed. Please try logging in again.');
        setLoading(false);
        // Redirect to login page
        navigate('/login', { replace: true });
      }
    }
  };

  // Check for OAuth2 success/failure in URL parameters
  useEffect(() => {
    const isOAuth2Success = searchParams.has('success') || 
                           window.location.pathname.includes('/auth/oauth2/success');
    const isOAuth2Failure = searchParams.has('error') || 
                           window.location.pathname.includes('/auth/oauth2/failure');

    if (isOAuth2Success) {
      handleOAuth2Success();
    } else if (isOAuth2Failure) {
      const errorMessage = searchParams.get('error') || 'OAuth2 authentication failed';
      setError(errorMessage);
      navigate('/login', { replace: true });
    }
  }, [searchParams, navigate]);

  // Check existing session on component mount
  useEffect(() => {
    const checkSession = async () => {
      try {
        const accountData = await account.get();
        setUser(accountData);
      } catch (error) {
        // User not authenticated, which is expected
        console.log('No existing session found');
      }
    };

    checkSession();
  }, []);

  if (loading) {
    return (
      <div className="auth-container">
        <div className="loading">
          <p>Authenticating...</p>
          <div className="spinner"></div>
        </div>
      </div>
    );
  }

  if (user) {
    return (
      <div className="auth-container">
        <div className="user-info">
          <h2>Welcome, {user.name}!</h2>
          <p>Email: {user.email}</p>
          <button 
            onClick={async () => {
              try {
                await account.deleteSession('current');
                setUser(null);
                navigate('/login');
              } catch (error) {
                console.error('Logout error:', error);
              }
            }}
          >
            Logout
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="auth-container">
      <div className="login-form">
        <h2>Login to Your Account</h2>
        
        {error && (
          <div className="error-message">
            <p>{error}</p>
          </div>
        )}
        
        <button 
          onClick={handleGoogleLogin}
          className="google-login-btn"
          disabled={loading}
        >
          Continue with Google
        </button>
        
        <div className="help-text">
          <p>
            <strong>Note:</strong> If you experience login issues with React Router v7, 
            the authentication system will automatically retry the session verification.
          </p>
        </div>
      </div>
    </div>
  );
}

/**
 * Alternative approach: Simple OAuth2 handler with page refresh
 * 
 * This is a simpler implementation that forces a page refresh after OAuth2
 * redirect to ensure cookies are properly established.
 */
export function SimpleOAuth2Handler() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();

  const handleGoogleLogin = () => {
    const successUrl = `${window.location.origin}/auth/success?refresh=true`;
    const failureUrl = `${window.location.origin}/auth/failure`;
    
    account.createOAuth2Session('google', successUrl, failureUrl);
  };

  useEffect(() => {
    // Check if we're returning from OAuth2 and need to refresh
    if (searchParams.get('refresh') === 'true') {
      // Clear the refresh parameter and reload the page
      const newUrl = new URL(window.location);
      newUrl.searchParams.delete('refresh');
      window.history.replaceState({}, '', newUrl.toString());
      
      // Force a page refresh to ensure cookies are properly loaded
      window.location.reload();
    }
  }, [searchParams]);

  return (
    <div className="simple-auth">
      <button onClick={handleGoogleLogin}>
        Login with Google (Simple)
      </button>
    </div>
  );
}

// CSS styles for the component
const styles = `
.auth-container {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 100vh;
  padding: 20px;
}

.login-form, .user-info, .loading {
  max-width: 400px;
  padding: 30px;
  border-radius: 8px;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
  background: white;
  text-align: center;
}

.google-login-btn {
  width: 100%;
  padding: 12px;
  background: #4285f4;
  color: white;
  border: none;
  border-radius: 4px;
  font-size: 16px;
  cursor: pointer;
  margin-bottom: 20px;
}

.google-login-btn:hover {
  background: #357ae8;
}

.google-login-btn:disabled {
  background: #ccc;
  cursor: not-allowed;
}

.error-message {
  background: #fee;
  border: 1px solid #fcc;
  color: #c00;
  padding: 10px;
  border-radius: 4px;
  margin-bottom: 20px;
}

.help-text {
  font-size: 14px;
  color: #666;
  margin-top: 20px;
}

.spinner {
  border: 4px solid #f3f3f3;
  border-top: 4px solid #3498db;
  border-radius: 50%;
  width: 40px;
  height: 40px;
  animation: spin 2s linear infinite;
  margin: 20px auto;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}
`;

export default OAuth2Handler;
