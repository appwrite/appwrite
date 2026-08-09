'use client'

import { Check, Lock, X } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { Card } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import { OAuth2AppAvatar } from '@/components/global/auth/OAuth2AppAvatar'
import type { OAuth2Flow, OAuth2Outcome } from './OAuth2ConsentCard'

interface OAuth2OutcomeCardProps {
  outcome: OAuth2Outcome
  flow: OAuth2Flow
  app?: Models.App | null
  accountLabel?: string
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
  accountLabel,
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
    <Card className="overflow-hidden p-6 md:p-8">
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
            <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
            <p className="text-muted-foreground text-[13px] leading-relaxed">
              {message}
            </p>
            {accountLabel ? (
              <p className="text-muted-foreground text-[12px]">
                {t('Signed in as')}{' '}
                <span className="text-foreground font-medium">
                  {accountLabel}
                </span>
              </p>
            ) : null}
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
            <p className="text-muted-foreground text-center text-[12px]">
              {t("It's safe to close this tab.")}
            </p>
          </div>
        ) : null}

        <p className="text-muted-foreground flex items-center justify-center gap-1.5 text-center text-[12px]">
          <Lock className="size-3.5" />
          {approved
            ? t('You can revoke access anytime in your account settings')
            : `${appName} ${t('was not given access to your account')}`}
        </p>
      </div>
    </Card>
  )
}
