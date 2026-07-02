import { Globe, Lock, ShoppingCart } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const DOMAIN_CAPABILITIES = [
  {
    id: 'buy',
    title: 'Buy domain',
    description: 'Register a domain in Appwrite without leaving the Console.',
    icon: ShoppingCart,
  },
  {
    id: 'dns',
    title: 'Appwrite DNS',
    description: 'Manage A, CNAME, TXT, and other records in one place.',
    icon: Globe,
  },
  {
    id: 'tls',
    title: 'Automatic TLS',
    description: 'Certificates are issued when a hostname is verified.',
    icon: Lock,
  },
] as const

type SitesDomainManagementProps = {
  className?: string
}

function CapabilityTile({
  item,
  className,
}: {
  item: (typeof DOMAIN_CAPABILITIES)[number]
  className?: string
}) {
  const t = useT()
  return (
    <div className={cn('p-4 sm:p-5', className)}>
      <span className="flex size-7 items-center justify-center rounded-md border border-border bg-muted/40">
        <item.icon className="size-3.5 text-muted-foreground" aria-hidden />
      </span>
      <h3 className="mt-3 text-[14px] font-semibold text-foreground">{t(item.title)}</h3>
      <p className="mt-1.5 text-[13px] leading-5 text-muted-foreground">{t(item.description)}</p>
    </div>
  )
}

export function SitesDomainManagement({ className }: SitesDomainManagementProps) {
  return (
    <div
      className={cn(
        'grid overflow-hidden rounded-xl border border-border bg-card/45 sm:grid-cols-3',
        className,
      )}
    >
      {DOMAIN_CAPABILITIES.map((item, index) => (
        <CapabilityTile
          key={item.id}
          item={item}
          className={cn(
            index < DOMAIN_CAPABILITIES.length - 1 &&
              'border-b border-border sm:border-b-0 sm:border-e',
          )}
        />
      ))}
    </div>
  )
}
