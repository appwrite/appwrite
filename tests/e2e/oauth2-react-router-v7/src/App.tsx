import { useState, useEffect } from 'react'
import { Routes, Route, useNavigate, useSearchParams } from 'react-router-dom'
import { Client, Account, Models, OAuthProvider } from 'appwrite'

// Configure Appwrite client
const client = new Client()
  .setEndpoint(import.meta.env.VITE_APPWRITE_ENDPOINT )
  .setProject(import.meta.env.VITE_APPWRITE_PROJECT_ID )

const account = new Account(client)

// Login component
function Login() {
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleGoogleLogin = async () => {
    try {
      setLoading(true)
      setError(null)
      
      // Use the callback URL from environment (.env file)
      const successUrl = `${window.location.origin}/auth/success`
      const failureUrl = `${window.location.origin}/auth/failure`
      
      // Appwrite handles provider callback; we only pass post-callback redirects.
      // No need to await; this triggers a navigation.
      void account.createOAuth2Session('google' as OAuthProvider, successUrl, failureUrl)
    } catch (error) {
      console.error('OAuth2 session creation error:', error)
      setError('Failed to initiate Google login')
      setLoading(false)
    }
  }

  return (
    <div style={{ padding: '20px', textAlign: 'center' }}>
      <h1>OAuth2 React Router v7 Test</h1>
      <p>Test the fix for 401 Unauthorized after Google OAuth sign-in</p>
      
      {error && (
        <div style={{ color: 'red', margin: '10px 0' }}>
          {error}
        </div>
      )}
      
      <button 
        onClick={handleGoogleLogin}
        disabled={loading}
        style={{
          padding: '10px 20px',
          fontSize: '16px',
          backgroundColor: '#4285f4',
          color: 'white',
          border: 'none',
          borderRadius: '4px',
          cursor: loading ? 'not-allowed' : 'pointer'
        }}
      >
        {loading ? 'Redirecting...' : 'Login with Google'}
      </button>
      
      <div style={{ marginTop: '20px', fontSize: '14px', color: '#666' }}>
      
        
       
       
      </div>
    </div>
  )
}

// Auth success handler
function AuthSuccess() {
  const navigate = useNavigate()
  const [user, setUser] = useState<Models.User<Models.Preferences> | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [retryCount, setRetryCount] = useState(0)

  const handleAuthSuccess = async (attempt = 0) => {
    const maxRetries = 3
    const delay = attempt === 0 ? 100 : 1000 * attempt // Progressive delay

    try {
      console.log(`Attempting to get user account (attempt ${attempt + 1})...`)
      
      // Wait for cookies to be properly set
      await new Promise(resolve => setTimeout(resolve, delay))

      // Try to get user account
      const userData = await account.get()
      
      console.log('✅ OAuth2 authentication successful:', userData)
      setUser(userData)
      setLoading(false)
      
      // Navigate to dashboard after short delay
      setTimeout(() => navigate('/dashboard'), 1000)
      
    } catch (error: any) {
      console.error(`❌ Attempt ${attempt + 1} failed:`, error)
      
      if (error.code === 401 && attempt < maxRetries) {
        console.log(`🔄 Retrying in ${delay}ms... (${attempt + 1}/${maxRetries})`)
        setRetryCount(attempt + 1)
        setTimeout(() => handleAuthSuccess(attempt + 1), delay)
      } else {
        setError(`Authentication failed after ${attempt + 1} attempts: ${error.message}`)
        setLoading(false)
        // Navigate back to login after delay
        setTimeout(() => navigate('/'), 3000)
      }
    }
  }

  useEffect(() => {
    handleAuthSuccess()
  }, [])

  return (
    <div style={{ padding: '20px', textAlign: 'center' }}>
      <h2>Processing OAuth2 Authentication...</h2>
      
      {loading && (
        <div>
          <div style={{ margin: '20px 0' }}>
            <div style={{
              border: '4px solid #f3f3f3',
              borderTop: '4px solid #3498db',
              borderRadius: '50%',
              width: '40px',
              height: '40px',
              animation: 'spin 2s linear infinite',
              margin: '0 auto'
            }}></div>
          </div>
          <p>Verifying session... {retryCount > 0 && `(Retry ${retryCount}/3)`}</p>
        </div>
      )}
      
      {user && (
        <div style={{ color: 'green' }}>
          <h3>✅ Authentication Successful!</h3>
          <p>Welcome, {user.name}!</p>
          <p>Email: {user.email}</p>
          <p>Redirecting to dashboard...</p>
        </div>
      )}
      
      {error && (
        <div style={{ color: 'red' }}>
          <h3>❌ Authentication Failed</h3>
          <p>{error}</p>
          <p>Redirecting to login page...</p>
        </div>
      )}
    </div>
  )
}

// Auth failure handler
function AuthFailure() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  
  useEffect(() => {
    setTimeout(() => navigate('/'), 3000)
  }, [navigate])

  return (
    <div style={{ padding: '20px', textAlign: 'center' }}>
      <h2 style={{ color: 'red' }}>Authentication Failed</h2>
      <p>Error: {searchParams.get('error') || 'Unknown error occurred'}</p>
      <p>Redirecting to login page in 3 seconds...</p>
    </div>
  )
}

// Dashboard component
function Dashboard() {
  const navigate = useNavigate()
  const [user, setUser] = useState<Models.User<Models.Preferences> | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const checkAuth = async () => {
      try {
        const userData = await account.get()
        setUser(userData)
      } catch (error) {
        console.error('Not authenticated:', error)
        navigate('/')
      } finally {
        setLoading(false)
      }
    }

    checkAuth()
  }, [navigate])

  const handleLogout = async () => {
    try {
      await account.deleteSession('current')
      navigate('/')
    } catch (error) {
      console.error('Logout error:', error)
    }
  }

  if (loading) {
    return <div style={{ padding: '20px', textAlign: 'center' }}>Loading...</div>
  }

  if (!user) {
    return null // Will redirect to login
  }

  return (
    <div style={{ padding: '20px', textAlign: 'center' }}>
      <h1>🎉 Dashboard</h1>
      <div style={{ margin: '20px 0', padding: '20px', border: '1px solid #ddd', borderRadius: '8px' }}>
        <h3>User Information</h3>
        <p><strong>Name:</strong> {user.name}</p>
        <p><strong>Email:</strong> {user.email}</p>
        <p><strong>User ID:</strong> {user.$id}</p>
        <p><strong>Created:</strong> {new Date(user.$createdAt).toLocaleString()}</p>
      </div>
      
      
      <button 
        onClick={handleLogout}
        style={{
          padding: '10px 20px',
          fontSize: '16px',
          backgroundColor: '#dc3545',
          color: 'white',
          border: 'none',
          borderRadius: '4px',
          cursor: 'pointer'
        }}
      >
        Logout
      </button>
    </div>
  )
}

// Main App component
function App() {
  return (
    <div>
      <Routes>
        <Route path="/" element={<Login />} />
        <Route path="/auth/success" element={<AuthSuccess />} />
        <Route path="/auth/failure" element={<AuthFailure />} />
        <Route path="/dashboard" element={<Dashboard />} />
      </Routes>
    </div>
  )
}

export default App