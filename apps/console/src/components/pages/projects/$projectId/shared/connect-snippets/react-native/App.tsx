import { useEffect, useState } from 'react'
import { Pressable, Text, View } from 'react-native'
import { Account } from 'react-native-appwrite'
import { client } from './lib/appwrite'
import { SignIn } from './pages/SignIn'
import { SignUp } from './pages/SignUp'

function Home({
  onGoToSignIn,
  onGoToSignUp,
}: {
  onGoToSignIn: () => void
  onGoToSignUp: () => void
}) {
  const [user, setUser] = useState<{ name: string } | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const account = new Account(client)
    account
      .get()
      .then((u) => setUser({ name: u.name }))
      .catch(() => setUser(null))
      .finally(() => setLoading(false))
  }, [])

  if (loading) {
    return <Text>Loading...</Text>
  }

  if (!user) {
    return (
      <View>
        <Text>Sign in to get started.</Text>
        <Text onPress={onGoToSignIn}>Sign in</Text>
        <Text onPress={onGoToSignUp}>Sign up</Text>
      </View>
    )
  }

  async function handleSignOut() {
    const account = new Account(client)
    await account.deleteSession({ sessionId: 'current' })
    setUser(null)
  }

  return (
    <View>
      <Text>Hello, {user.name}</Text>
      <Pressable onPress={handleSignOut}>
        <Text>Sign out</Text>
      </Pressable>
    </View>
  )
}

export default function App() {
  const [route, setRoute] = useState('home')

  return (
    <View style={{ flex: 1, justifyContent: 'center', padding: 24, gap: 8 }}>
      {route === 'sign-in' ? (
        <SignIn
          onSignedIn={() => setRoute('home')}
          onGoToSignUp={() => setRoute('sign-up')}
        />
      ) : route === 'sign-up' ? (
        <SignUp
          onSignedUp={() => setRoute('home')}
          onGoToSignIn={() => setRoute('sign-in')}
        />
      ) : (
        <Home
          onGoToSignIn={() => setRoute('sign-in')}
          onGoToSignUp={() => setRoute('sign-up')}
        />
      )}
    </View>
  )
}
