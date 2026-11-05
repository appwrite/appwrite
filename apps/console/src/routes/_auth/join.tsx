import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  createFileRoute,
  useNavigate,
  useSearch,
  useRouter,
} from '@tanstack/react-router'
import { z } from 'zod'
import { AppwriteException, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  AcceptInviteFlow,
  type AcceptInviteScreen,
} from '@/components/global/auth/AcceptInviteFlow'
import { toast } from 'sonner'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useT } from '@/lib/i18n/translate'
import { pageTitle } from '@/lib/utils/page-title'
import { unescapeInviteTeamName } from '@/lib/auth/invite-team-name'
import {
  refreshConsoleAccountAfterAuth,
  performConsoleSignOut,
} from '@/lib/react-query/hooks/auth'

/** Current /join URL (path + query), used to return here after switching accounts. */
function getJoinRedirectUrl(): string {
  if (typeof window === 'undefined') return '/join'
  return `${window.location.pathname}${window.location.search}`
}

const searchSchema = z.object({
  teamId: z.string().optional(),
  membershipId: z.string().optional(),
  userId: z.string().optional(),
  secret: z.string().optional(),
  teamName: z.string().optional(),
})

export const Route = createFileRoute('/_auth/join')({
  component: AcceptInvitePage,
  validateSearch: searchSchema,
  head: () => ({ meta: [{ title: pageTitle('Accept invite') }] }),
})

function AcceptInvitePage() {
  const t = useT()
  const search = useSearch({ from: '/_auth/join' })
  const navigate = useNavigate()
  const router = useRouter()
  const queryClient = useQueryClient()
  const { account: accountUnknown, isLoading } = useAuth()
  const account = accountUnknown as Models.User | undefined
  const [accepted, setAccepted] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [errorIsAccountMismatch, setErrorIsAccountMismatch] = useState(false)
  const [isSwitchingAccount, setIsSwitchingAccount] = useState(false)
  const teamName = unescapeInviteTeamName(search.teamName)

  const hasAllParams =
    search.teamId && search.membershipId && search.userId && search.secret

  const isWrongAccount =
    !!hasAllParams && !!account && account.$id !== search.userId

  const handleSwitchAccount = async () => {
    if (isSwitchingAccount) return
    setIsSwitchingAccount(true)
    try {
      await performConsoleSignOut(queryClient, {
        destination: getJoinRedirectUrl(),
        requireServerRevocation: true,
      })
    } catch {
      setIsSwitchingAccount(false)
      toast.error(t('Could not sign out. Try switching accounts again.'))
    }
  }

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
      toast.success(t('Successfully joined the organization!'))
      try {
        await refreshConsoleAccountAfterAuth(queryClient)
        await router.invalidate()
      } catch {
        // The membership is confirmed; the destination can retry account loading.
      }
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
    onError: (err: unknown) => {
      const errorMessage =
        (err instanceof Error && err.message) ||
        t('Failed to accept invitation')
      setError(errorMessage)
      setErrorIsAccountMismatch(
        err instanceof AppwriteException && err.type === 'team_invite_mismatch',
      )
      toast.error(errorMessage)
    },
  })

  const handleAccept = () => {
    setError(null)
    setErrorIsAccountMismatch(false)
    acceptMutation.mutate()
  }

  const accountLabel = account?.email || account?.name || undefined
  const isBusy = acceptMutation.isPending || isSwitchingAccount

  let screen: AcceptInviteScreen
  if (isLoading && !accepted) {
    screen = 'loading'
  } else if (accepted) {
    screen = 'success'
  } else if (isWrongAccount) {
    screen = 'wrong-account'
  } else if (error) {
    screen = 'error'
  } else if (!hasAllParams) {
    screen = 'invalid'
  } else {
    screen = 'accept'
  }

  return (
    <AcceptInviteFlow
      screen={screen}
      teamName={teamName}
      accountLabel={accountLabel}
      errorMessage={error}
      errorIsAccountMismatch={errorIsAccountMismatch}
      isBusy={isBusy}
      onAccept={handleAccept}
      onSwitchAccount={handleSwitchAccount}
      onGoToDashboard={() => navigate({ to: '/' })}
    />
  )
}
