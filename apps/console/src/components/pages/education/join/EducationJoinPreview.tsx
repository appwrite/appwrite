import { Loader2, TriangleAlert } from 'lucide-react'
import { AuthFlowAccountSwitcherStatic } from '@/components/global/auth/AuthFlowAccountSwitcherStatic'
import {
  AuthFlowDescription,
  AuthFlowNarrowCard,
  AuthFlowTitle,
  authFlowMetaClassName,
} from '@/components/global/auth/AuthFlowCard'
import { AuthFlowHeaderIcon } from '@/components/global/auth/AuthFlowHeaderIcon'
import { EducationJoinPartnerHeader } from '@/components/pages/education/join/EducationJoinPartnerHeader'
import { AuthFlowShell } from '@/components/global/auth/AuthFlowShell'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { Button } from '@/components/ui/button'
import { DEBUG_DEMO_MOCK_EMAIL } from '@/lib/debug-demos/constants'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { GitHubIcon } from '@/lib/vcs/providers'

export type EducationJoinPreviewState =
  | 'landing'
  | 'oauth-failure'
  | 'loading'
  | 'ineligible'

type EducationJoinPreviewProps = {
  state?: EducationJoinPreviewState
  accountLabel?: string
}

export function EducationJoinPreview({
  state = 'landing',
  accountLabel = DEBUG_DEMO_MOCK_EMAIL,
}: EducationJoinPreviewProps) {
  const t = useT()

  return (
    <AuthFlowShell
      width="narrow"
      accountSwitcher={
        state === 'loading' ? null : (
          <AuthFlowAccountSwitcherStatic accountLabel={accountLabel} preview />
        )
      }
    >
      <AuthFlowNarrowCard>
        {state === 'loading' ? (
          <div className="flex flex-col items-center gap-4 py-8 text-center">
            <Loader2 className="h-8 w-8 animate-spin text-muted-foreground motion-reduce:animate-none" />
            <AuthFlowDescription>
              {t('Checking your account...')}
            </AuthFlowDescription>
          </div>
        ) : state === 'ineligible' ? (
          <div className="space-y-6">
            <div className="flex flex-col items-center gap-4 text-center">
              <AuthFlowHeaderIcon icon={TriangleAlert} variant="destructive" />
              <div className="space-y-2">
                <AuthFlowTitle>
                  {t(
                    "It looks like you're not currently eligible for the GitHub Student Developer Pack.",
                  )}
                </AuthFlowTitle>
                <AuthFlowDescription>
                  {t('You can still use Appwrite without an Education plan.')}
                </AuthFlowDescription>
              </div>
            </div>
            <Button
              variant="brandCta"
              className="w-full"
              type="button"
              disabled
            >
              <GitHubIcon className="size-4 shrink-0" />
              {t('Connect GitHub')}
            </Button>
            <Button className="w-full" variant="outline" type="button" disabled>
              {t('Continue to Appwrite')}
            </Button>
          </div>
        ) : (
          <div className="space-y-6">
            <div className="flex flex-col items-center gap-4 text-center">
              <EducationJoinPartnerHeader />
              <div className="space-y-2">
                <AuthFlowTitle>
                  {t('Join the Appwrite Education Program')}
                </AuthFlowTitle>
                <AuthFlowDescription>
                  {t(
                    'Enjoy Appwrite Cloud for free throughout your student journey as part of the GitHub Student Developer Pack.',
                  )}
                </AuthFlowDescription>
              </div>
            </div>
            <Button
              variant="brandCta"
              className="w-full"
              type="button"
              disabled
            >
              <GitHubIcon className="size-4 shrink-0" />
              {t('Connect GitHub')}
            </Button>
            {state === 'oauth-failure' ? (
              <AuthFlowDescription>
                {t(
                  'GitHub did not complete the sign in. Try again to join the program.',
                )}
              </AuthFlowDescription>
            ) : null}
            <p className={cn(authFlowMetaClassName, 'text-center')}>
              <MarketingSiteLink className="link-neutral" href="/education">
                {t('Read about the program')}
              </MarketingSiteLink>
            </p>
          </div>
        )}
      </AuthFlowNarrowCard>
    </AuthFlowShell>
  )
}
