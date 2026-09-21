import type { ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

export type AuthFlowShellWidth = 'narrow' | 'illustration'

/** Shared max width for single-column auth pages (invite, OAuth, education join, etc.). */
export const authFlowShellNarrowWidthClassName = 'max-w-lg'

const WIDTH_CLASS: Record<AuthFlowShellWidth, string> = {
  narrow: authFlowShellNarrowWidthClassName,
  illustration: 'max-w-lg md:max-w-4xl',
}

type AuthFlowShellProps = {
  children: ReactNode
  width?: AuthFlowShellWidth
  showLegal?: boolean
  showLogo?: boolean
  /**
   * Replaces the default Terms/Privacy line when set (including `null` to hide).
   * Rendered below the card, above the account switcher.
   */
  footer?: ReactNode | null
  /** Rendered after the footer and before the logo. */
  accountSwitcher?: ReactNode
  className?: string
}

export const authFlowShellFooterClassName =
  'mt-6 text-center text-xs text-muted-foreground'

export const authFlowShellFooterRowClassName = cn(
  authFlowShellFooterClassName,
  'flex flex-wrap items-center justify-center gap-x-1.5 gap-y-1',
)

export function AuthFlowShell({
  children,
  width = 'narrow',
  showLegal = true,
  showLogo = true,
  footer,
  accountSwitcher,
  className,
}: AuthFlowShellProps) {
  const t = useT()

  const defaultLegal = (
    <p className={authFlowShellFooterClassName}>
      {t('By clicking continue, you agree to our')}{' '}
      <MarketingSiteLink className="link-neutral" href="/terms">
        {t('Terms of Service')}
      </MarketingSiteLink>{' '}
      {t('and')}{' '}
      <MarketingSiteLink className="link-neutral" href="/privacy">
        {t('Privacy Policy')}
      </MarketingSiteLink>
      .
    </p>
  )

  return (
    <div
      className={cn('bg-background relative h-full overflow-y-auto', className)}
    >
      <div className="flex min-h-full flex-col items-center p-6 md:p-10">
        <div className={cn('my-auto w-full min-w-0', WIDTH_CLASS[width])}>
          {children}
          {footer !== undefined ? (
            footer ? (
              <div className={authFlowShellFooterRowClassName}>{footer}</div>
            ) : null
          ) : showLegal ? (
            defaultLegal
          ) : null}
          {accountSwitcher ? (
            <div className="mt-8 flex justify-center md:mt-10">
              {accountSwitcher}
            </div>
          ) : null}
          {showLogo ? (
            <div
              className={cn(
                'flex justify-center',
                accountSwitcher ? 'mt-8 md:mt-10' : 'mt-10 md:mt-16',
              )}
            >
              <Link
                to="/"
                aria-label="Appwrite"
                className="inline-flex rounded-lg transition-transform duration-150 ease-out hover:opacity-90 active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background motion-reduce:active:scale-100"
              >
                <AppwriteLogo className="h-6 w-auto" />
              </Link>
            </div>
          ) : null}
        </div>
      </div>
    </div>
  )
}

/** Shared cover image column for illustration auth cards (sign-in, recovery, etc.). */
export function AuthFlowIllustrationColumn() {
  return (
    <div className="hidden min-h-[600px] bg-background md:block">
      <img
        alt=""
        className="h-full w-full object-cover"
        height={600}
        src="/cover.avif"
        width={600}
      />
    </div>
  )
}
