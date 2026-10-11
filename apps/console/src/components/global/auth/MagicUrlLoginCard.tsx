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
}

export function MagicUrlLoginCard({
  errorMessage,
  signInTo = '/sign-in',
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
