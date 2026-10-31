import { Link } from '@tanstack/react-router'
import { Loader2 } from 'lucide-react'
import {
  AuthFlowDescription,
  AuthFlowIllustrationCard,
  AuthFlowTitle,
} from '@/components/global/auth/AuthFlowCard'
import {
  AuthFlowIllustrationColumn,
  AuthFlowShell,
} from '@/components/global/auth/AuthFlowShell'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'

type MagicUrlLoginCardProps = {
  errorMessage?: string | null
  signInTo?: string
  /** Ask before replacing the account this browser is signed in to. */
  switchAccount?: {
    email: string
    pending?: boolean
    onContinue: () => void
    onCancel: () => void
  }
}

export function MagicUrlLoginCard({
  errorMessage,
  signInTo = '/sign-in',
  switchAccount,
}: MagicUrlLoginCardProps) {
  const t = useT()
  const hasError = !!errorMessage

  return (
    <AuthFlowShell width="illustration">
      <AuthFlowIllustrationCard illustration={<AuthFlowIllustrationColumn />}>
        <div className="space-y-6">
          {hasError ? (
            <>
              <div className="space-y-2">
                <AuthFlowTitle>{t('Unable to sign you in')}</AuthFlowTitle>
                <AuthFlowDescription>{errorMessage}</AuthFlowDescription>
              </div>
              <Link to={signInTo}>
                <Button variant="brandCta" className="w-full">
                  {t('Go to sign in')}
                </Button>
              </Link>
            </>
          ) : switchAccount ? (
            <>
              <div className="space-y-2">
                <AuthFlowTitle>{t('Switch account')}</AuthFlowTitle>
                <AuthFlowDescription>
                  {t("You're signed in as")}{' '}
                  <span className="text-foreground font-medium break-all">
                    {switchAccount.email}
                  </span>
                  .
                </AuthFlowDescription>
                <AuthFlowDescription>
                  {t('Continue and sign in to this project instead?')}
                </AuthFlowDescription>
              </div>
              <div className="space-y-2">
                <Button
                  variant="brandCta"
                  className="w-full"
                  disabled={switchAccount.pending}
                  onClick={switchAccount.onContinue}
                >
                  {switchAccount.pending && (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  )}
                  {t('Continue')}
                </Button>
                <Button
                  variant="outline"
                  className="w-full"
                  disabled={switchAccount.pending}
                  onClick={switchAccount.onCancel}
                >
                  {t('Cancel')}
                </Button>
              </div>
            </>
          ) : (
            <div className="space-y-2">
              <AuthFlowTitle>{t('Signing you in')}</AuthFlowTitle>
              <AuthFlowDescription className="flex items-center gap-2">
                <Loader2 className="h-4 w-4 animate-spin" />
                {t('Please wait while we confirm your magic URL.')}
              </AuthFlowDescription>
            </div>
          )}
        </div>
      </AuthFlowIllustrationCard>
    </AuthFlowShell>
  )
}
