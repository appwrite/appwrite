import { useEffect, useState } from 'react'
import './App.css'
import { client } from './lib/appwrite'
import { Account } from 'appwrite'
import { SignIn } from './pages/SignIn'
import { SignUp } from './pages/SignUp'

function Home() {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const account = new Account(client)
    account
      .get()
      .then((u) => setUser({ name: u.name }))
      .catch(() => setUser(null))
      .finally(() => setLoading(false))
  }, [])

  async function handleSignOut() {
    const account = new Account(client)
    await account.deleteSession({ sessionId: 'current' })
    setUser(null)
  }

  if (loading) {
    return <p>Loading...</p>
  }

  return (
    <div>
      {user ? (
        <div>
          <p>Hello, {user.name}</p>
          <button type="button" onClick={handleSignOut}>
            Sign out
          </button>
        </div>
      ) : (
        <div>
          <p>Sign in to get started.</p>
          <p>
            <a href="/sign-in">Sign in</a>
            {' · '}
            <a href="/sign-up">Sign up</a>
          </p>
        </div>
      )}
    </div>
  )
}

function App() {
  const path = window.location.pathname
  if (path === '/sign-in') return <SignIn />
  if (path === '/sign-up') return <SignUp />
  return <Home />
}

export default App
