'use client'

import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { useT } from '@/lib/i18n/translate'

const linkClassName = 'link-unstyled product-bento-link'

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
