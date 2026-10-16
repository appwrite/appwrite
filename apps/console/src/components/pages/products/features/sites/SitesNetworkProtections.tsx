import { CheckCircle2 } from 'lucide-react'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const NETWORK_PROTECTIONS = [
  'Global CDN',
  'DDoS protection',
  'TLS encryption',
  'Edge SSR',
] as const

type SitesNetworkProtectionsProps = {
  className?: string
}

export function SitesNetworkProtections({ className }: SitesNetworkProtectionsProps) {
  const t = useT()
  return (
    <div className={cn('flex flex-wrap justify-center gap-2', className)}>
      {NETWORK_PROTECTIONS.map((label, index) => (
        <span
          key={label}
          className="product-hero-rise inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-3 py-1.5 text-[13px] font-medium text-foreground shadow-sm dark:bg-card"
          style={riseStyle(100 + index * 90)}
        >
          <CheckCircle2
            className="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"
            aria-hidden
          />
          {t(label)}
        </span>
      ))}
    </div>
  )
}
