import { useState } from 'react'
import { Pressable, Text, TextInput, View } from 'react-native'
import { Account } from 'react-native-appwrite'
import { client } from '../lib/appwrite'

export function SignIn({
  onSignedIn,
  onGoToSignUp,
}: {
  onSignedIn: () => void
  onGoToSignUp: () => void
}) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')

  async function handleSubmit() {
    if (!email || !password) return
    setError('')
    try {
      const account = new Account(client)
      await account.createEmailPasswordSession({ email, password })
      onSignedIn()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Sign in failed')
    }
  }

  return (
    <View>
      <Text>Sign in</Text>
      {error ? <Text>{error}</Text> : null}
      <TextInput
        placeholder="Email"
        value={email}
        onChangeText={setEmail}
        keyboardType="email-address"
        autoCapitalize="none"
      />
      <TextInput
        placeholder="Password"
        value={password}
        onChangeText={setPassword}
        secureTextEntry
      />
      <Pressable onPress={handleSubmit}>
        <Text>Sign in</Text>
      </Pressable>
      <View style={{ flexDirection: 'row' }}>
        <Text>No account? </Text>
        <Text onPress={onGoToSignUp}>Sign up</Text>
      </View>
    </View>
  )
}
