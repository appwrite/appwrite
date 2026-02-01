import { useState, useEffect } from 'react'
import { useMutation } from '@tanstack/react-query'
import {
  createFileRoute,
  useNavigate,
  useSearch,
  useRouter,
} from '@tanstack/react-router'
import { z } from 'zod'
import { sdk } from '@/lib/appwrite/sdk'
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { toast } from 'sonner'
import { CheckCircle, XCircle, Loader2 } from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'

const searchSchema = z.object({
  teamId: z.string().optional(),
  membershipId: z.string().optional(),
  userId: z.string().optional(),
  secret: z.string().optional(),
  teamName: z.string().optional(), // Optional team name from URL (not trusted)
})

export const Route = createFileRoute('/_auth/join')({
  component: AcceptInvitePage,
  validateSearch: searchSchema,
  loader: async () => {
    // Authentication check is handled by RequireAuth component
    // Team name verification happens client-side in the component
    return {}
  },
})

function AcceptInvitePage() {
  const { isAuthenticated, isLoading } = useAuth()
  const navigate = useNavigate()

  // Redirect to sign-in if not authenticated (but allow loading state)
  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      // Get raw query string to preserve all parameters
      // Always use '/join' as the pathname since we're on the join page
      const rawSearch =
        typeof window !== 'undefined' ? window.location.search : ''
      const searchStr = rawSearch.startsWith('?')
        ? rawSearch.slice(1)
        : rawSearch
      const redirectUrl = `/join${searchStr ? `?${searchStr}` : ''}`

      // Only redirect if we have a valid relative URL
      if (redirectUrl.startsWith('/') && !redirectUrl.includes('://')) {
        navigate({ to: '/sign-in', search: { redirect: redirectUrl } })
      } else {
        navigate({ to: '/sign-in' })
      }
    }
  }, [isLoading, isAuthenticated, navigate])

  // Show loading state while checking auth
  if (isLoading) {
    return (
      <div className="bg-background relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    )
  }

  // Don't render content if not authenticated (redirect is in progress)
  if (!isAuthenticated) {
    return null
  }

  return <AcceptInviteContent />
}

function AcceptInviteContent() {
  const search = useSearch({ from: '/_auth/join' })
  const navigate = useNavigate()
  const router = useRouter()
  const [accepted, setAccepted] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [teamName, setTeamName] = useState<string | null>(null)

  // Check if we have all required parameters
  const hasAllParams =
    search.teamId && search.membershipId && search.userId && search.secret

  // Verify the invitation and get team name (client-side)
  useEffect(() => {
    if (hasAllParams) {
      sdk.forConsole.teams
        .get(search.teamId!)
        .then((team) => {
          return sdk.forConsole.teams
            .listMemberships(search.teamId!, [])
            .then((membershipsResponse) => {
              const matchingMembership = membershipsResponse.memberships?.find(
                (m: any) =>
                  m.$id === search.membershipId && m.userId === search.userId,
              )
              if (matchingMembership) {
                setTeamName(team.name || null)
              }
            })
        })
        .catch((err) => {
          console.warn('Failed to verify invitation:', err)
        })
    }
  }, [hasAllParams, search.teamId, search.membershipId, search.userId])

  const acceptMutation = useMutation({
    mutationFn: async () => {
      if (!hasAllParams) {
        throw new Error('Missing required invitation parameters')
      }
      return await sdk.forConsole.teams.updateMembershipStatus({
        teamId: search.teamId!,
        membershipId: search.membershipId!,
        userId: search.userId!,
        secret: search.secret!,
      })
    },
    onSuccess: async () => {
      setAccepted(true)
      toast.success('Successfully joined the organization!')
      // Invalidate router to refresh auth state
      await router.invalidate()
      // Redirect to the organization page after a short delay
      setTimeout(() => {
        if (search.teamId) {
          navigate({
            to: '/organizations/$orgId',
            params: { orgId: search.teamId },
          })
        } else {
          navigate({ to: '/' })
        }
      }, 2000)
    },
    onError: (err: any) => {
      const errorMessage = err?.message || 'Failed to accept invitation'
      setError(errorMessage)
      toast.error(errorMessage)
    },
  })

  const handleAccept = () => {
    setError(null)
    acceptMutation.mutate()
  }

  return (
    <div className="bg-background relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
      <div className="w-full max-w-sm md:max-w-4xl">
        <Card className="overflow-hidden py-0">
          <div className="grid md:grid-cols-2">
            <div className="p-6 md:p-10 min-h-[600px] flex flex-col justify-center">
              {accepted ? (
                <div className="flex flex-col items-center text-center space-y-4">
                  <div className="flex h-16 w-16 items-center justify-center rounded-full bg-green-500/10">
                    <CheckCircle className="h-8 w-8 text-green-500" />
                  </div>
                  <div className="space-y-2">
                    <h1 className="text-2xl font-semibold tracking-tight">
                      Welcome to the organization!
                    </h1>
                    <p className="text-sm text-muted-foreground">
                      You've successfully joined. Redirecting you now...
                    </p>
                  </div>
                </div>
              ) : error ? (
                <div className="flex flex-col items-center text-center space-y-4">
                  <div className="flex h-16 w-16 items-center justify-center rounded-full bg-red-500/10">
                    <XCircle className="h-8 w-8 text-red-500" />
                  </div>
                  <div className="space-y-2">
                    <h1 className="text-2xl font-semibold tracking-tight">
                      Unable to accept invitation
                    </h1>
                    <p className="text-sm text-muted-foreground">{error}</p>
                  </div>
                  <Button
                    onClick={() => navigate({ to: '/sign-in' })}
                    variant="outline"
                    className="mt-4"
                  >
                    Go to Sign In
                  </Button>
                </div>
              ) : !hasAllParams ? (
                <div className="flex flex-col items-center text-center space-y-4">
                  <div className="flex h-16 w-16 items-center justify-center rounded-full bg-amber-500/10">
                    <XCircle className="h-8 w-8 text-amber-500" />
                  </div>
                  <div className="space-y-2">
                    <h1 className="text-2xl font-semibold tracking-tight">
                      Invalid invitation link
                    </h1>
                    <p className="text-sm text-muted-foreground">
                      This invitation link is missing required parameters.
                      Please use the link from your invitation email.
                    </p>
                  </div>
                  <Button
                    onClick={() => navigate({ to: '/sign-in' })}
                    variant="outline"
                    className="mt-4"
                  >
                    Go to Sign In
                  </Button>
                </div>
              ) : (
                <div className="space-y-6">
                  <div className="space-y-2">
                    <h1 className="text-2xl font-semibold tracking-tight">
                      Accept invitation
                    </h1>
                    {teamName ? (
                      <p className="text-sm text-muted-foreground">
                        You've been invited to join{' '}
                        <span className="font-medium text-foreground">
                          {teamName}
                        </span>
                        . Accept the invitation to get started.
                      </p>
                    ) : (
                      <p className="text-sm text-muted-foreground">
                        You've been invited to join an organization. Accept the
                        invitation to get started.
                      </p>
                    )}
                  </div>

                  <div className="space-y-4">
                    <Button
                      onClick={handleAccept}
                      disabled={acceptMutation.isPending || !hasAllParams}
                      className="w-full"
                    >
                      Accept invitation
                    </Button>
                  </div>
                </div>
              )}
            </div>
            <div className="hidden bg-background md:block min-h-[600px]">
              <img
                alt="Image"
                className="h-full w-full object-cover"
                height="600"
                src="/cover.png"
                width="600"
              />
            </div>
          </div>
        </Card>
        <p className="mt-6 text-center text-xs text-muted-foreground">
          By accepting this invitation, you agree to our{' '}
          <a
            href="#"
            className="underline underline-offset-4 hover:text-primary"
          >
            Terms of Service
          </a>{' '}
          and{' '}
          <a
            href="#"
            className="underline underline-offset-4 hover:text-primary"
          >
            Privacy Policy
          </a>
          .
        </p>
        <div className="mt-10 md:mt-16 flex justify-center">
          <AppwriteLogo className="h-6 w-auto" />
        </div>
      </div>
    </div>
  )
}
