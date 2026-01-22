import { createFileRoute, redirect } from '@tanstack/react-router'
import { sdk } from '@/lib/appwrite/sdk'

export const Route = createFileRoute('/_auth/sign-out')({
  loader: async () => {
    try {
      // Delete all sessions (client-side) - await to ensure completion before redirect
      await sdk.forConsole.account.deleteSessions()
    } catch (error) {
      // Ignore errors - we'll redirect anyway
      console.error('Error signing out:', error)
    }
    throw redirect({ to: '/' })
  },
})
