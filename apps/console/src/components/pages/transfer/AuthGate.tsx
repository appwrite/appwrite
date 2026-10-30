import { useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { Check, Clock, FolderInput, TriangleAlert } from 'lucide-react'
import { toast } from 'sonner'
import {
  AuthFlowDescription,
  AuthFlowNarrowCard,
  AuthFlowTitle,
} from '@/components/global/auth/AuthFlowCard'
import { AuthFlowHeaderIcon } from '@/components/global/auth/AuthFlowHeaderIcon'
import { AuthFlowShell } from '@/components/global/auth/AuthFlowShell'
import { Button } from '@/components/ui/button'
import { useNow } from '@/hooks/use-now'
import type { ZeroProject } from '@/lib/appwrite/zero'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT, type Translator } from '@/lib/i18n/translate'
import { performConsoleSignOut } from '@/lib/react-query/hooks/auth'
import { useAccountZero } from '@/lib/react-query/hooks/zero'
import { formatRemaining, getRemainingMs } from '@/lib/zero/countdown'

type AuthPage = '/sign-up' | '/sign-in'

/**
 * Asks whoever opened a transfer link without a console account to sign up or
 * sign in, then brings them back to it. `anonymous` is the owner of an
 * unclaimed Zero project, whose session has to end first.
 */
export function TransferAuthGate({
  transferId,
  secret,
  anonymous,
}: {
  transferId: string
  secret: string
  anonymous: boolean
}) {
  const t = useT()
  const now = useNow()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [isLeaving, setIsLeaving] = useState(false)
  const { data: zero } = useAccountZero({ enabled: anonymous, poll: true })

  const transferPath = `/transfer?${new URLSearchParams({ transferId, secret })}`

  const copy = describeTransferGate(t, now, anonymous ? zero : undefined)
  // A claimed project's link is spent, so signing in should not come back to it.
  const redirect = copy.state === 'claimed' ? undefined : transferPath

  const leaveForAuth = async (page: AuthPage) => {
    if (!anonymous) {
      navigate({ to: page, search: { redirect } })
      return
    }
    // The anonymous session would bounce sign-in and sign-up back to the console.
    setIsLeaving(true)
    try {
      await performConsoleSignOut(queryClient, {
        destination: redirect
          ? `${page}?redirect=${encodeURIComponent(redirect)}`
          : page,
        requireServerRevocation: true,
      })
    } catch {
      setIsLeaving(false)
      toast.error(t('Could not sign out. Try again.'))
    }
  }

  return (
    <main id="main-content" className="h-[100dvh] max-h-[100dvh]">
      <AuthFlowShell width="narrow" showLegal={false}>
        <AuthFlowNarrowCard>
          <div className="space-y-6">
            <div className="flex flex-col items-center gap-4 text-center">
              <AuthFlowHeaderIcon
                icon={copy.icon}
                variant={copy.state === 'deleted' ? 'destructive' : 'neutral'}
              />
              <div className="space-y-1">
                <AuthFlowTitle>{copy.title}</AuthFlowTitle>
                <AuthFlowDescription>{copy.description}</AuthFlowDescription>
              </div>
              {copy.deadline ? (
                <p className="text-[13px] text-muted-foreground">
                  {copy.deadline}
                </p>
              ) : null}
            </div>
            <div className="flex flex-col gap-2">
              {copy.state !== 'claimed' && (
                <Button
                  variant="brandCta"
                  className="w-full"
                  onClick={() => void leaveForAuth('/sign-up')}
                  disabled={isLeaving}
                  {...(anonymous ? analyticsAttrs('zero-claim') : {})}
                >
                  {t('Create an account')}
                </Button>
              )}
              {copy.state !== 'deleted' && (
                <Button
                  variant={copy.state === 'claimed' ? 'brandCta' : 'outline'}
                  className="w-full"
                  onClick={() => void leaveForAuth('/sign-in')}
                  disabled={isLeaving}
                >
                  {t('Sign in')}
                </Button>
              )}
            </div>
          </div>
        </AuthFlowNarrowCard>
      </AuthFlowShell>
    </main>
  )
}

export type TransferGateState = 'claim' | 'claimed' | 'deleted'

/** What the gate tells a visitor, from the Zero project the anonymous owner holds. */
export function describeTransferGate(
  t: Translator,
  now: number,
  zero: ZeroProject | undefined,
) {
  if (!zero) {
    return {
      icon: FolderInput,
      title: t('Claim project'),
      description: t('Sign in or create an account to claim this project.'),
      deadline: null,
      state: 'claim' as TransferGateState,
    }
  }

  if (zero.status === 'claimed') {
    return {
      icon: Check,
      title: t('Project already claimed'),
      description: t(
        'This project now belongs to an account. Sign in to that account to open it.',
      ),
      deadline: null,
      state: 'claimed' as TransferGateState,
    }
  }

  if (zero.status === 'deleted' || getRemainingMs(zero.claimBy, now) === 0) {
    return {
      icon: TriangleAlert,
      title: t('Project deleted'),
      description: t(
        'This project was not claimed in time, so it and its data were deleted.',
      ),
      deadline: null,
      state: 'deleted' as TransferGateState,
    }
  }

  const expired =
    zero.status === 'expired' || getRemainingMs(zero.expiresAt, now) === 0
  return {
    icon: Clock,
    title: t('Claim your project'),
    description: expired
      ? t(
          'The build window ended and the project is paused. Create an account to claim it and bring it back with all its data.',
        )
      : t(
          'Create an account to keep this project, with everything already built in it.',
        ),
    deadline: expired
      ? t('Claim within {time} or the project is deleted.').replace(
          '{time}',
          formatRemaining(getRemainingMs(zero.claimBy, now)),
        )
      : t('{time} left to build').replace(
          '{time}',
          formatRemaining(getRemainingMs(zero.expiresAt, now)),
        ),
    state: 'claim' as TransferGateState,
  }
}
