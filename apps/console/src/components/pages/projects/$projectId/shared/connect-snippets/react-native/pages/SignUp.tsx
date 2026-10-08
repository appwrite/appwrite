import { useState } from 'react'
import { Pressable, Text, TextInput, View } from 'react-native'
import { Account, ID } from 'react-native-appwrite'
import { client } from '../lib/appwrite'

export function SignUp({
  onSignedUp,
  onGoToSignIn,
}: {
  onSignedUp: () => void
  onGoToSignIn: () => void
}) {
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')

  async function handleSubmit() {
    if (!email || !password) return
    setError('')
    try {
      const account = new Account(client)
      await account.create({
        userId: ID.unique(),
        email,
        password,
        name: name.trim() || undefined,
      })
      await account.createEmailPasswordSession({ email, password })
      onSignedUp()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Sign up failed')
    }
  }

  return (
    <View>
      <Text>Sign up</Text>
      {error ? <Text>{error}</Text> : null}
      <TextInput placeholder="Name" value={name} onChangeText={setName} />
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
        <Text>Sign up</Text>
      </Pressable>
      <View style={{ flexDirection: 'row' }}>
        <Text>Already have an account? </Text>
        <Text onPress={onGoToSignIn}>Sign in</Text>
      </View>
    </View>
  )
}
