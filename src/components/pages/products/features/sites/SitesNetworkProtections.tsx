import { CheckCircle2 } from 'lucide-react'
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
      {NETWORK_PROTECTIONS.map((label) => (
        <div
          key={label}
          className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-muted/20 px-3 py-2 text-[13px] font-medium text-foreground"
        >
          <CheckCircle2
            className="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"
            aria-hidden
          />
          {t(label)}
        </div>
      ))}
    </div>
  )
}
