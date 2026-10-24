import { Link } from '@tanstack/react-router'
import { Clock, ShieldCheck } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { FullScreenCurtain } from '@/components/global/shared/FullScreenCurtain'
import { useNow } from '@/hooks/use-now'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { useAccountZero } from '@/lib/react-query/hooks/zero'
import { formatRemaining, getRemainingMs } from '@/lib/zero/countdown'

/**
 * Shown instead of the paused curtain when a Zero project's build window has
 * closed: only claiming the project resumes it.
 */
export function ZeroExpiredCurtain() {
  const t = useT()
  const now = useNow()
  const { data: zero } = useAccountZero({ poll: true })

  return (
    <FullScreenCurtain
      icon={Clock}
      title={t('Build window ended')}
      description={t(
        'This project is paused and its data is kept until the claim deadline. Claim it to keep building.',
      )}
      actions={
        zero?.secret ? (
          <Button asChild variant="brandCta" className="gap-1.5">
            <Link
              to="/zero/$zeroId"
              params={{ zeroId: zero.$id }}
              search={{ secret: zero.secret }}
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
