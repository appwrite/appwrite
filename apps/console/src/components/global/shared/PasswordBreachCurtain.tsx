'use client'

import { useState } from 'react'
import { Link, useLocation } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { KeyRound, ShieldAlert, Smartphone } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  isOptionalAuthPage,
  useAuth,
} from '@/components/global/auth/RequireAuth'
import { FullScreenCurtain } from '@/components/global/shared/FullScreenCurtain'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import { isMarketingPagePath } from '@/lib/marketing/is-marketing-page'

const DISMISSED_STORAGE_KEY = 'console.passwordBreachCurtain.dismissed'
const SECURITY_PATH = '/account/security'

function shouldSuppressOnPath(pathname: string): boolean {
  // The fix happens on the security page, so the curtain must not cover it.
  if (pathname === SECURITY_PATH || pathname.startsWith(`${SECURITY_PATH}/`)) {
    return true
  }
  if (isOptionalAuthPage(pathname)) return true
  if (isMarketingPagePath(pathname)) return true
  if (pathname.startsWith('/debug/')) return true
  return false
}

function readDismissedAccountId(): string | null {
  if (typeof sessionStorage === 'undefined') return null
  try {
    return sessionStorage.getItem(DISMISSED_STORAGE_KEY)
  } catch {
    return null
  }
}

function writeDismissedAccountId(accountId: string) {
  if (typeof sessionStorage === 'undefined') return
  try {
    sessionStorage.setItem(DISMISSED_STORAGE_KEY, accountId)
  } catch {
    // Storage can be unavailable (private mode); the dismissal then lasts until reload.
  }
}

/**
 * Full-screen curtain for console accounts whose password was found in a
 * known data breach. Urges a password change, and MFA when it is off.
 * Dismissal lasts for the browser tab session so the prompt returns later.
 */
export function PasswordBreachCurtain() {
  const t = useT()
  const { account, isAuthenticated } = useAuth()
  const { features } = useConsoleProfile()
  const location = useLocation()
  const [dismissedAccountId, setDismissedAccountId] = useState(
    readDismissedAccountId,
  )

  const user = isAuthenticated
    ? (account as Models.User | undefined)
    : undefined

  if (!user || user.passwordPwned !== true) return null
  if (dismissedAccountId === user.$id) return null
  if (shouldSuppressOnPath(location.pathname)) return null

  const suggestMfa = features.accountMfa && user.mfa !== true

  const steps = [
    {
      icon: KeyRound,
      title: t('Set a new password'),
      description: t("Pick a strong password you don't use anywhere else."),
    },
    ...(suggestMfa
      ? [
          {
            icon: Smartphone,
            title: t('Turn on multi-factor authentication'),
            description: t(
              'Require a second verification step every time you sign in.',
            ),
          },
        ]
      : []),
  ]

  const handleDismiss = () => {
    writeDismissedAccountId(user.$id)
    setDismissedAccountId(user.$id)
  }

  return (
    <FullScreenCurtain
      role="dialog"
      className="z-[130]"
      icon={ShieldAlert}
      tone="destructive"
      title={t('Your password was found in a data breach')}
      description={t(
        'Your security is important to us. We continuously check console passwords against known data breaches, and yours was found in one, which means others may be able to sign in as you.',
      )}
      actions={
        <>
          <Button asChild variant="brandCta" className="min-w-40">
            <Link to={SECURITY_PATH}>
              {suggestMfa ? t('Secure account') : t('Update password')}
            </Link>
          </Button>
          <Button variant="outline" onClick={handleDismiss}>
            {t('Remind me later')}
          </Button>
        </>
      }
    >
      <ul className="mt-6 w-full divide-y divide-border overflow-hidden rounded-xl border border-border bg-card/50 text-start">
        {steps.map((step) => (
          <li key={step.title} className="flex items-start gap-3 px-4 py-3.5">
            <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
              <step.icon className="size-4" aria-hidden />
            </div>
            <div className="min-w-0">
              <p className="text-[14px] font-medium text-foreground">
                {step.title}
              </p>
              <p className="mt-0.5 text-[13px] text-muted-foreground">
                {step.description}
              </p>
            </div>
          </li>
        ))}
      </ul>
    </FullScreenCurtain>
  )
}
