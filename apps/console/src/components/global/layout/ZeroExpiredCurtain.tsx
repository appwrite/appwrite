import { Link } from '@tanstack/react-router'
import { Clock, ShieldCheck, TriangleAlert } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { FullScreenCurtain } from '@/components/global/shared/FullScreenCurtain'
import { useNow } from '@/hooks/use-now'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { useAccountZero } from '@/lib/react-query/hooks/zero'
import { formatRemaining, getRemainingMs } from '@/lib/zero/countdown'
import { getZeroPhase } from '@/lib/zero/phase'
import { getZeroTransferSearch } from '@/lib/zero/session'

/**
 * Shown instead of the paused curtain when a Zero project's build window has
 * closed: only claiming the project resumes it.
 */
export function ZeroExpiredCurtain() {
  const t = useT()
  const now = useNow()
  const { data: zero } = useAccountZero({ poll: true })
  const transfer = getZeroTransferSearch(zero?.claimUrl)
  const phase = zero ? getZeroPhase(zero, now) : null

  if (phase === 'deleted') {
    return (
      <FullScreenCurtain
        icon={TriangleAlert}
        title={t('Project deleted')}
        description={t(
          'This project was not claimed in time, so it and its data were deleted.',
        )}
      />
    )
  }

  if (phase === 'claimed') {
    return (
      <FullScreenCurtain
        icon={ShieldCheck}
        title={t('Project already claimed')}
        description={t(
          'This project now belongs to an account. Sign in to that account to open it.',
        )}
      />
    )
  }

  return (
    <FullScreenCurtain
      icon={Clock}
      title={t('Build window ended')}
      description={t(
        'This project is paused and its data is kept until the claim deadline. Claim it to keep building.',
      )}
      actions={
        transfer ? (
          <Button asChild variant="brandCta" className="gap-1.5">
            <Link
              to="/transfer"
              search={transfer}
              {...analyticsAttrs('zero-claim')}
            >
              <ShieldCheck className="size-4" />
              {t('Claim project')}
            </Link>
          </Button>
        ) : null
      }
    >
      {zero?.claimBy ? (
        <p className="mt-2 text-[14px] text-muted-foreground">
          {t('Claim within {time} or the project is deleted.').replace(
            '{time}',
            formatRemaining(getRemainingMs(zero.claimBy, now)),
          )}
        </p>
      ) : null}
    </FullScreenCurtain>
  )
}
