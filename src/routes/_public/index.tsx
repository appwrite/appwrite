import { createFileRoute, useNavigate } from '@tanstack/react-router'
import { useEffect } from 'react'
import { useAuth } from '@/components/global/auth/RequireAuth'

export const Route = createFileRoute('/_public/')({
  component: RootRedirect,
})

function RootRedirect() {
  const { account } = useAuth()
  const navigate = useNavigate()

  useEffect(() => {
    if (!account) return // Wait for auth

    // Get org ID from account prefs
    const orgId = account.prefs?.organization as string | undefined

    if (orgId) {
      navigate({
        to: '/organizations/$orgId',
        params: { orgId },
        replace: true,
      })
      return
    }

    // Fallback to onboarding if no organization preference
    navigate({
      to: '/onboarding',
      replace: true,
    })
  }, [account, navigate])

  // Show loading while redirecting
  return (
    <div className="flex h-screen items-center justify-center">
      <div className="text-muted-foreground">Loading...</div>
    </div>
  )
}
