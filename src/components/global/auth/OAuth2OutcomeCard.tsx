'use client'

import { Check, Lock, X } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import {
  AuthFlowDescription,
  AuthFlowNarrowCard,
  AuthFlowTitle,
  authFlowMetaClassName,
} from '@/components/global/auth/AuthFlowCard'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { OAuth2AppAvatar } from '@/components/global/auth/OAuth2AppAvatar'
import { authFlowShellFooterRowClassName } from '@/components/global/auth/AuthFlowShell'
import type { OAuth2Flow, OAuth2Outcome } from './OAuth2ConsentCard'

interface OAuth2OutcomeCardProps {
  outcome: OAuth2Outcome
  flow: OAuth2Flow
  app?: Models.App | null
  /**
   * Present when the client redirect is a native deep link (e.g. `cursor://`).
   * The browser already attempted it once; the button retries for users who
   * dismissed the OS "open application" prompt.
   */
  redirectUrl?: string
}

export function OAuth2OutcomeCard({
  outcome,
  flow,
  app = null,
  redirectUrl,
}: OAuth2OutcomeCardProps) {
  const t = useT()
  const approved = outcome === 'approved'
  const appName = app?.name ?? t('the application')
  const StatusIcon = approved ? Check : X

  const title = approved
    ? flow === 'device'
      ? t('Device connected')
      : t('Access granted')
    : t('Request cancelled')

  let message: string
  if (!approved) {
    message = `${t('No access was granted to')} ${appName}. ${t('You can close this tab.')}`
  } else if (flow === 'device') {
    message = `${t("You've authorized")} ${appName}. ${t('Return to your device - it will continue automatically.')}`
  } else if (redirectUrl) {
    message = `${t('Return to')} ${appName} ${t('to continue. If it didn’t open automatically, use the button below.')}`
  } else {
    message = `${t('Return to')} ${appName} ${t('to continue. You can close this tab.')}`
  }

  return (
    <>
      <AuthFlowNarrowCard>
        <div className="space-y-6">
        <div className="flex flex-col items-center gap-4 text-center">
          <div className="relative">
            <OAuth2AppAvatar app={app} />
            <span
              className={
                approved
                  ? 'absolute -end-1 -bottom-1 flex size-5 items-center justify-center rounded-full border-2 border-[var(--card)] bg-emerald-500 text-white'
                  : 'bg-muted text-muted-foreground absolute -end-1 -bottom-1 flex size-5 items-center justify-center rounded-full border-2 border-[var(--card)]'
              }
            >
              <StatusIcon className="size-3" />
            </span>
          </div>
          <div className="space-y-1">
            <AuthFlowTitle>{title}</AuthFlowTitle>
            <AuthFlowDescription>{message}</AuthFlowDescription>
          </div>
        </div>

        {approved && redirectUrl ? (
          <div className="space-y-2">
            <Button
              variant="brandCta"
              className="w-full"
              onClick={() => {
                window.location.href = redirectUrl
              }}
            >
              {t('Open')} {app?.name ?? t('application')}
            </Button>
            <p className={cn(authFlowMetaClassName, 'text-center')}>
              {t("It's safe to close this tab.")}
            </p>
          </div>
        ) : null}
        </div>
      </AuthFlowNarrowCard>
      <div className={authFlowShellFooterRowClassName}>
        <OAuth2OutcomeShellFooter outcome={outcome} app={app} />
      </div>
    </>
  )
}

/** Shell footer below the OAuth outcome card. */
export function OAuth2OutcomeShellFooter({
  outcome,
  app = null,
}: {
  outcome: OAuth2Outcome
  app?: Models.App | null
}) {
  const t = useT()
  const approved = outcome === 'approved'
  const appName = app?.name ?? t('the application')

  return (
    <>
      <Lock className="size-3.5 shrink-0" />
      <span>
        {approved
          ? t('You can revoke access anytime in your account settings')
          : `${appName} ${t('was not given access to your account')}`}
      </span>
    </>
  )
}
