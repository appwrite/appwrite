'use client'

import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { useT } from '@/lib/i18n/translate'

const linkClassName =
  'link-unstyled absolute inset-0 z-[1] rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background'

type ProductBentoCardLinkProps = {
  href: string
  title: string
}

export function ProductBentoCardLink({ href, title }: ProductBentoCardLinkProps) {
  const t = useT()
  const label = `${t('Learn more about')} ${title}`

  return (
    <MarketingSiteLink href={href} className={linkClassName} aria-label={label}>
      <span className="sr-only">{label}</span>
    </MarketingSiteLink>
  )
}
