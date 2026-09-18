import { Link, useNavigate } from '@tanstack/react-router'
import { AlertTriangle, Mail, MessageCircle } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import { APPWRITE_SUPPORT_EMAIL } from '@/lib/utils/error-formatting'
import { PROJECT_BLOCKED_CURTAIN } from '@/lib/project-blocks'
import { useOrganizationPlan } from '@/lib/react-query/hooks'

type BlockedProjectCurtainProps = {
  teamId: string
}

/**
 * Full-screen curtain for projects with an active moderation block.
 * Uses generic copy only; never shows the API block reason.
 */
export function BlockedProjectCurtain({
  teamId,
}: BlockedProjectCurtainProps) {
  const t = useT()
  const navigate = useNavigate()
  const { plan } = useOrganizationPlan(teamId)
  const hasPremiumSupport = plan?.premiumSupport === true

  return (
    <div className="fixed inset-0 z-[120] flex items-center justify-center bg-background/95 backdrop-blur-sm">
      <div className="mx-4 flex max-w-md flex-col items-center text-center">
        <div className="mb-6 flex size-16 items-center justify-center rounded-full bg-destructive/10 text-destructive">
          <AlertTriangle className="size-9" aria-hidden />
        </div>
        <h1 className="text-[22px] font-semibold text-foreground">
          {t(PROJECT_BLOCKED_CURTAIN.title)}
        </h1>
        <p className="mt-3 text-[15px] text-muted-foreground">
          {t(PROJECT_BLOCKED_CURTAIN.message)}
        </p>

        <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:gap-2">
          {hasPremiumSupport ? (
            <Button asChild variant="brandCta" className="gap-1.5">
              <Link
                to="/organizations/$orgId/support"
                params={{ orgId: teamId }}
              >
                <MessageCircle className="size-4" />
                {t('Contact support')}
              </Link>
            </Button>
          ) : (
            <Button asChild variant="brandCta" className="gap-1.5">
              <a href={`mailto:${APPWRITE_SUPPORT_EMAIL}`}>
                <Mail className="size-4" />
                {t('Contact support')}
              </a>
            </Button>
          )}
          <Button
            variant="outline"
            onClick={() =>
              navigate({
                to: '/organizations/$orgId',
                params: { orgId: teamId },
              })
            }
          >
            {t('Back to organization')}
          </Button>
        </div>
      </div>
    </div>
  )
}
