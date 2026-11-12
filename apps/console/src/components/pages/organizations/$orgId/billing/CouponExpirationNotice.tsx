import { cn } from '@/lib/utils'
import type { Models } from '@appwrite.io/console'
import { useT } from '@/lib/i18n/translate'

interface CouponExpirationNoticeProps {
  coupon: Pick<Models.Coupon, 'validity'>
  variant?: 'default' | 'compact'
  className?: string
}

export function CouponExpirationNotice({
  coupon,
  variant = 'default',
  className,
}: CouponExpirationNoticeProps) {
  const t = useT()
  const hasCreditValidity =
    typeof coupon.validity === 'number' && coupon.validity > 0

  if (!hasCreditValidity) {
    return null
  }

  const textClass = variant === 'compact' ? 'text-[12px]' : 'text-[13px]'

  const message = (
    <p className={cn('text-muted-foreground leading-relaxed', textClass)}>
      {t('Credits expire')} {coupon.validity}{' '}
      {coupon.validity === 1 ? t('day') : t('days')}{' '}
      {t('after redemption and do not roll over.')}
    </p>
  )

  if (variant === 'compact') {
    return <div className={cn(className)}>{message}</div>
  }

  return (
    <div
      className={cn(
        'rounded-lg border border-border bg-muted/30 px-4 py-3 space-y-1',
        className,
      )}
    >
      <p className="text-[13px] font-medium text-foreground">{t('Credit expiration')}</p>
      {message}
    </div>
  )
}
