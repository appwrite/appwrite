import { useState } from 'react'
import { Link } from '@tanstack/react-router'
import { Clock, TriangleAlert } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useNow } from '@/hooks/use-now'
import {
  HeaderAlertBar,
  headerAlertOutlineButtonClass,
} from '@/components/global/shared/HeaderAlertBar'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { useZero } from '@/lib/react-query/hooks/zero'
import { formatRemaining, getRemainingMs } from '@/lib/zero/countdown'
import { isZeroAccount, readZeroSession } from '@/lib/zero/session'

/**
 * Counts down a Zero project's build and claim windows while the console is
 * signed in as its anonymous owner.
 */
export function ZeroBanner({ className }: { className?: string }) {
  const { account } = useAuth()
  if (!isZeroAccount(account as Models.User | undefined)) return null
  return <ZeroBannerContent className={className} />
}

function ZeroBannerContent({ className }: { className?: string }) {
  const t = useT()
  const now = useNow()
  const [session] = useState(readZeroSession)
  const { data: zero } = useZero(session?.zeroId, session?.secret, {
    poll: true,
  })

  const expiresAt = zero?.expiresAt ?? session?.expiresAt
  const claimBy = zero?.claimBy ?? session?.claimBy

  const claimLink = session ? (
    <Link
      to="/zero/$zeroId"
      params={{ zeroId: session.zeroId }}
      search={{ secret: session.secret }}
      className={headerAlertOutlineButtonClass(
        expiresAt && getRemainingMs(expiresAt, now) > 0 ? 'info' : 'warning',
      )}
      {...analyticsAttrs('zero-claim')}
    >
      {t('Claim project')}
    </Link>
  ) : null

  if (!session || !expiresAt || !claimBy) {
    return (
      <HeaderAlertBar
        variant="info"
        icon={Clock}
        role="status"
        className={className}
      >
        {t(
          'This is a temporary project. Open the claim link you were given to keep it.',
        )}
      </HeaderAlertBar>
    )
  }

  const buildRemaining = getRemainingMs(expiresAt, now)
  const claimRemaining = getRemainingMs(claimBy, now)

  if (zero?.status === 'deleted' || claimRemaining === 0) {
    return (
      <HeaderAlertBar
        variant="danger"
        icon={TriangleAlert}
        role="alert"
        className={className}
      >
        {t('This project was not claimed in time and has been deleted.')}
      </HeaderAlertBar>
    )
  }

  if (zero?.status === 'expired' || buildRemaining === 0) {
    return (
      <HeaderAlertBar
        variant="warning"
        icon={TriangleAlert}
        role="alert"
        className={className}
        action={claimLink}
      >
        {t('Build window ended.')}{' '}
        {t('Claim within {time} or the project is deleted.').replace(
          '{time}',
          formatRemaining(claimRemaining),
        )}
      </HeaderAlertBar>
    )
  }

  return (
    <HeaderAlertBar
      variant="info"
      icon={Clock}
      role="status"
      className={className}
      action={claimLink}
    >
      {t('Zero project')}
      {' · '}
      {t('{time} left to build').replace(
        '{time}',
        formatRemaining(buildRemaining),
      )}
      {' · '}
      {t('Claim it to keep it')}
    </HeaderAlertBar>
  )
}
