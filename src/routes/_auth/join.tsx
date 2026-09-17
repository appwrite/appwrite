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
import { AuthAccountChip } from '@/components/global/auth/AuthAccountChip'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { toast } from 'sonner'
import { CheckCircle, Loader2, Lock, UserRoundX, XCircle } from 'lucide-react'
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

  // Check if we have all required parameters
  const hasAllParams =
    search.teamId && search.membershipId && search.userId && search.secret

  // The invite carries the target userId - if it doesn't match the signed-in
  // account, accepting is guaranteed to fail, so surface that upfront.
  const isWrongAccount =
    !!hasAllParams && !!account && account.$id !== search.userId

  // The invitation secret establishes the invitee's session after signing out.
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
    onError: (err: unknown) => {
      const errorMessage =
        (err instanceof Error && err.message) ||
        t('Failed to accept invitation')
      setError(errorMessage)
      // Switching accounts only helps when the invite targets another account.
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

  // Resolve any existing account before accepting, but allow guests to use the
  // invitation secret. Keep the success screen mounted during account refresh.
  if (isLoading && !accepted) {
    return (
      <div className="bg-background relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const inviteDescription = teamName ? (
    <>
      {t("You've been invited to join")}{' '}
      <span className="text-foreground font-medium break-words">
        {teamName}
      </span>
      {'. '}
      {t('Accept the invitation to get started.')}
    </>
  ) : (
    t(
      "You've been invited to join an organization. Accept the invitation to get started.",
    )
  )

  return (
    <div className="bg-background h-full overflow-y-auto">
      <div className="flex min-h-full flex-col items-center p-6 md:p-10">
        <div className="my-auto w-full min-w-0 max-w-md">
          <Card className="w-full min-w-0 overflow-hidden p-6 md:p-8">
            <div className="space-y-6">
              {accepted ? (
                <div className="flex flex-col items-center gap-4 text-center">
                  <div className="bg-muted text-muted-foreground flex size-14 items-center justify-center rounded-xl ring-1 ring-border/50">
                    <CheckCircle className="size-6 text-green-500" />
                  </div>
                  <div className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                      {t('Welcome to the organization!')}
                    </h1>
                    <p className="text-muted-foreground text-[13px] leading-relaxed">
                      {t("You've successfully joined. Redirecting you now...")}
                    </p>
                  </div>
                  {accountLabel ? (
                    <AuthAccountChip accountLabel={accountLabel} />
                  ) : null}
                </div>
              ) : isWrongAccount ? (
                <>
                  <div className="flex flex-col items-center gap-4 text-center">
                    <div className="bg-muted text-muted-foreground flex size-14 items-center justify-center rounded-xl ring-1 ring-border/50">
                      <UserRoundX className="size-6 text-amber-500" />
                    </div>
                    <div className="space-y-1">
                      <h1 className="text-2xl font-semibold tracking-tight">
                        {t("You're signed in with a different account")}
                      </h1>
                      <p className="text-muted-foreground text-[13px] leading-relaxed">
                        {t('This invitation was sent to a different account.')}{' '}
                        {t(
                          'Switch to the account the invitation was sent to in order to accept it.',
                        )}
                      </p>
                    </div>
                    {accountLabel ? (
                      <AuthAccountChip
                        accountLabel={accountLabel}
                        onSwitchAccount={handleSwitchAccount}
                        disabled={isBusy}
                      />
                    ) : null}
                  </div>
                  <div className="flex flex-col gap-2">
                    <Button
                      variant="brandCta"
                      className="w-full"
                      onClick={handleSwitchAccount}
                      disabled={isBusy}
                    >
                      {t('Use a different account')}
                    </Button>
                    <Button
                      variant="outline"
                      className="w-full"
                      onClick={() => navigate({ to: '/' })}
                      disabled={isBusy}
                    >
                      {t('Go to dashboard')}
                    </Button>
                  </div>
                </>
              ) : error ? (
                <>
                  <div className="flex flex-col items-center gap-4 text-center">
                    <div className="bg-muted text-muted-foreground flex size-14 items-center justify-center rounded-xl ring-1 ring-border/50">
                      <XCircle className="size-6 text-destructive" />
                    </div>
                    <div className="space-y-1">
                      <h1 className="text-2xl font-semibold tracking-tight">
                        {t('Unable to accept invitation')}
                      </h1>
                      <p className="text-muted-foreground text-[13px] leading-relaxed">
                        {error}
                      </p>
                    </div>
                    {accountLabel ? (
                      <AuthAccountChip
                        accountLabel={accountLabel}
                        onSwitchAccount={
                          errorIsAccountMismatch
                            ? handleSwitchAccount
                            : undefined
                        }
                        disabled={isBusy}
                      />
                    ) : null}
                  </div>
                  <div className="flex flex-col gap-2">
                    {errorIsAccountMismatch ? (
                      <Button
                        variant="brandCta"
                        className="w-full"
                        onClick={handleSwitchAccount}
                        disabled={isBusy}
                      >
                        {t('Use a different account')}
                      </Button>
                    ) : null}
                    <Button
                      variant="outline"
                      className="w-full"
                      onClick={() => navigate({ to: '/' })}
                      disabled={isBusy}
                    >
                      {t('Go to dashboard')}
                    </Button>
                  </div>
                </>
              ) : !hasAllParams ? (
                <>
                  <div className="flex flex-col items-center gap-4 text-center">
                    <div className="bg-muted text-muted-foreground flex size-14 items-center justify-center rounded-xl ring-1 ring-border/50">
                      <XCircle className="size-6 text-amber-500" />
                    </div>
                    <div className="space-y-1">
                      <h1 className="text-2xl font-semibold tracking-tight">
                        {t('Invalid invitation link')}
                      </h1>
                      <p className="text-muted-foreground text-[13px] leading-relaxed">
                        {t(
                          'This invitation link is missing required parameters. Please use the link from your invitation email.',
                        )}
                      </p>
                    </div>
                    {accountLabel ? (
                      <AuthAccountChip accountLabel={accountLabel} />
                    ) : null}
                  </div>
                  <Button
                    variant="outline"
                    className="w-full"
                    onClick={() => navigate({ to: '/' })}
                  >
                    {t('Go to dashboard')}
                  </Button>
                </>
              ) : (
                <>
                  <div className="flex flex-col items-center gap-4 text-center">
                    <div className="space-y-1">
                      <h1 className="text-2xl font-semibold tracking-tight">
                        {t('Accept invitation')}
                      </h1>
                      <p className="text-muted-foreground text-[13px] leading-relaxed">
                        {inviteDescription}
                      </p>
                    </div>
                    {accountLabel ? (
                      <AuthAccountChip
                        accountLabel={accountLabel}
                        onSwitchAccount={handleSwitchAccount}
                        disabled={isBusy}
                      />
                    ) : null}
                  </div>
                  <div className="flex flex-col gap-2">
                    <Button
                      variant="brandCta"
                      className="w-full"
                      onClick={handleAccept}
                      disabled={isBusy || !hasAllParams}
                    >
                      {t('Accept invitation')}
                    </Button>
                  </div>
                  <p className="text-muted-foreground flex flex-wrap items-center justify-center gap-x-1.5 gap-y-1 text-center text-[12px]">
                    <span className="inline-flex items-center gap-1">
                      <Lock className="size-3.5" />
                      {t('By accepting this invitation, you agree to our')}
                    </span>
                    <MarketingSiteLink className="link-neutral" href="/terms">
                      {t('Terms of Service')}
                    </MarketingSiteLink>
                    <span>{t('and')}</span>
                    <MarketingSiteLink className="link-neutral" href="/privacy">
                      {t('Privacy Policy')}
                    </MarketingSiteLink>
                  </p>
                </>
              )}
            </div>
          </Card>
        </div>
      </div>
    </div>
  )
}
