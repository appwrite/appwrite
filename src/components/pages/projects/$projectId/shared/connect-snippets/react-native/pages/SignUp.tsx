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
    // On the web the browser blocks this via the inputs' `required`
    // attribute; native inputs have no equivalent, so check it here.
    if (!email || !password) return
    setError('')
    try {
      const account = new Account(client)
      await account.create({
        userId: ID.unique(),
        email,
        password,
        // Appwrite rejects an empty name, so omit the key entirely.
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
      <Text>
        Already have an account? <Text onPress={onGoToSignIn}>Sign in</Text>
      </Text>
    </View>
  )
}
