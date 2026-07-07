'use client'

import { Check, Lock, X } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { Card } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
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

function Avatar({
  app,
  fallback,
}: {
  app?: Models.App | null
  fallback: string
}) {
  if (app?.logoUri) {
    return (
      <img
        src={app.logoUri}
        alt={app.name}
        className="size-14 rounded-xl border object-cover"
      />
    )
  }
  return (
    <div className="bg-muted text-muted-foreground flex size-14 items-center justify-center rounded-xl border text-xl font-semibold">
      {fallback}
    </div>
  )
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
  const appInitial = (app?.name || '?').charAt(0).toUpperCase()
  const accountInitial = (accountLabel || '?').charAt(0).toUpperCase()

  const title = approved
    ? flow === 'device'
      ? t('Device connected')
      : t('Access granted')
    : t('Request cancelled')

  let message: string
  if (!approved) {
    message = `${t('No access was granted to')} ${appName}. ${t('You can close this tab.')}`
  } else if (flow === 'device') {
    message = `${t("You've authorized")} ${appName}. ${t('Return to your device — it will continue automatically.')}`
  } else if (redirectUrl) {
    message = `${t('Return to')} ${appName} ${t('to continue. If it didn’t open automatically, use the button below.')}`
  } else {
    message = `${t('Return to')} ${appName} ${t('to continue. You can close this tab.')}`
  }

  const StatusIcon = approved ? Check : X

  return (
    <Card className="overflow-hidden p-6 md:p-8">
      <div className="space-y-6">
        <div className="flex flex-col items-center gap-5 text-center">
          {/* Identity row */}
          <div className="flex items-center justify-center">
            <div className="relative">
              <Avatar app={app} fallback={appInitial} />
              {!accountLabel && (
                <span
                  className={cn(
                    'absolute -end-1 -bottom-1 flex size-6 items-center justify-center rounded-full border-2 border-[var(--card)]',
                    approved
                      ? 'bg-emerald-500 text-white'
                      : 'bg-muted text-muted-foreground',
                  )}
                >
                  <StatusIcon className="size-3.5" />
                </span>
              )}
            </div>

            {accountLabel && (
              <>
                <div className="flex items-center px-2">
                  <span
                    className={cn(
                      'h-px w-4',
                      approved ? 'bg-emerald-500' : 'bg-border',
                    )}
                  />
                  <span
                    className={cn(
                      'flex size-7 items-center justify-center rounded-full',
                      approved
                        ? 'bg-emerald-500 text-white'
                        : 'bg-muted text-muted-foreground',
                    )}
                  >
                    <StatusIcon className="size-4" />
                  </span>
                  <span
                    className={cn(
                      'h-px w-4',
                      approved ? 'bg-emerald-500' : 'bg-border',
                    )}
                  />
                </div>
                <div className="bg-foreground text-background flex size-12 items-center justify-center rounded-full text-lg font-semibold">
                  {accountInitial}
                </div>
              </>
            )}
          </div>

          <div className="space-y-1">
            <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
            <p className="text-muted-foreground text-sm">{message}</p>
          </div>
        </div>

        {approved && redirectUrl && (
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
            <p className="text-muted-foreground text-center text-xs">
              {t("It's safe to close this tab.")}
            </p>
          </div>
        )}

        <p className="text-muted-foreground flex items-center justify-center gap-1.5 text-center text-xs">
          <Lock className="size-3.5" />
          {approved
            ? t('You can revoke access anytime in your account settings')
            : `${appName} ${t('was not given access to your account')}`}
        </p>
      </div>
    </Card>
  )
}
