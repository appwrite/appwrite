import { Link, useNavigate } from '@tanstack/react-router'
import { Lock } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'

type BudgetLimitProjectCurtainProps = {
  /** Organization id for billing CTA; may be unresolved briefly after a 402 */
  teamId?: string | null
}

/**
 * Full-screen curtain shown when the organization has reached its budget cap.
 * Blocks access to all project-scoped pages until the budget limit is updated.
 * Shown when project-scoped APIs return HTTP 402 (payment / budget required).
 */
export function BudgetLimitProjectCurtain({
  teamId,
}: BudgetLimitProjectCurtainProps) {
  const t = useT()
  const navigate = useNavigate()
  const hasTeamId = !!teamId

  return (
    <div className="fixed inset-0 z-[200] flex items-center justify-center bg-background/95 backdrop-blur-sm">
      <div className="mx-4 flex max-w-md flex-col items-center text-center">
        <div className="mb-6 flex size-16 items-center justify-center rounded-full bg-muted text-muted-foreground">
          <Lock className="size-9" />
        </div>
        <h1 className="text-[22px] font-semibold text-foreground">
          {t('Budget limit reached')}
        </h1>
        <p className="mt-3 text-[15px] text-muted-foreground">
          {t(
            'This organization has reached its budget limit and is now blocked. To continue using Appwrite services, update the budget limit.',
          )}
        </p>

        <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:gap-2">
          {hasTeamId ? (
            <Button asChild variant="brandCta" className="gap-1.5">
              <Link
                to="/organizations/$orgId/settings/billing"
                params={{ orgId: teamId }}
                hash="update-budget"
              >
                {t('Update limit')}
              </Link>
            </Button>
          ) : null}
          <Button
            variant={hasTeamId ? 'outline' : 'brandCta'}
            onClick={() => {
              if (hasTeamId) {
                navigate({
                  to: '/organizations/$orgId',
                  params: { orgId: teamId },
                })
                return
              }
              navigate({ to: '/' })
            }}
          >
            {hasTeamId ? t('Back to organization') : t('Back to console')}
          </Button>
        </div>
      </div>
    </div>
  )
}
