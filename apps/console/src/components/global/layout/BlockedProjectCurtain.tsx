import { Link, useNavigate } from '@tanstack/react-router'
import { AlertTriangle, Mail, MessageCircle } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { FullScreenCurtain } from '@/components/global/shared/FullScreenCurtain'
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
    <FullScreenCurtain
      icon={AlertTriangle}
      tone="destructive"
      title={t(PROJECT_BLOCKED_CURTAIN.title)}
      description={t(PROJECT_BLOCKED_CURTAIN.message)}
      actions={
        <>
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
        </>
      }
    />
  )
}
