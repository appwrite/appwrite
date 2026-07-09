import { Copy, Server } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'
import { productBentoContainer, productBentoIdle } from '@/components/pages/home/product-bento/MockSyntax'

const COMPATIBLE_TOOLS = [
  { id: 'rclone', name: 'rclone', iconSrc: '/icons/rclone.svg' },
  { id: 'terraform', name: 'Terraform', iconSrc: '/icons/terraform.svg' },
  { id: 'aws-cli', name: 'AWS CLI', iconSrc: '/icons/amazon.svg' },
  { id: 'cyberduck', name: 'Cyberduck', iconSrc: '/icons/cyberduck.svg' },
] as const

function MockCredentialRow({
  label,
  value,
  masked = false,
  delayMs = 0,
}: {
  label: string
  value: string
  masked?: boolean
  delayMs?: number
}) {
  return (
    <div
      className={cn(
        'rounded-md border border-border/70 px-2.5 py-2 transition-[border-color,background-color] duration-300',
        productBentoContainer.panelMd,
        'group-hover:border-[color-mix(in_srgb,var(--brand-cta)_22%,var(--border))] group-hover:bg-background motion-reduce:group-hover:border-border/70 motion-reduce:group-hover:bg-card/70',
      )}
      style={{ transitionDelay: `${delayMs}ms` }}
    >
      <p className="text-[9px] uppercase tracking-wider text-muted-foreground sm:text-[10px]">
        {label}
      </p>
      <div className="mt-1 flex items-center gap-1.5">
        <p
          className={cn(
            'min-w-0 flex-1 truncate font-mono text-[10px] sm:text-[11px]',
            productBentoIdle.text,
          )}
        >
          {masked ? value.replace(/./g, '•') : value}
        </p>
        <Copy className="size-3 shrink-0 text-muted-foreground" aria-hidden />
      </div>
    </div>
  )
}

export function S3StorageDayVisual() {
  return (
    <div className="absolute inset-0 flex flex-col overflow-hidden">
      <div
        className={cn(
          'mx-auto flex h-full min-h-0 w-full max-w-[22rem] flex-col',
          productBentoContainer.shell,
        )}
      >
        <div className={cn(productBentoContainer.header, 'px-3 py-2.5')}>
          <div className="flex items-start justify-between gap-2">
            <div className="flex min-w-0 items-start gap-2">
              <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-background">
                <Server className="size-3.5 text-muted-foreground" aria-hidden />
              </span>
              <div className="min-w-0">
                <p className={cn('text-[11px] font-medium sm:text-[12px]', productBentoIdle.text)}>
                  S3-compatible access
                </p>
                <p className="mt-0.5 text-[10px] text-muted-foreground sm:text-[11px]">
                  Project-scoped endpoint with SigV4 signing
                </p>
              </div>
            </div>
            <Badge
              variant="inactive"
              className="h-5 shrink-0 px-1.5 text-[9px] transition-[color,background-color,border-color] duration-300 group-hover:border-[var(--brand-cta)]/25 group-hover:bg-[var(--brand-cta)]/10 group-hover:text-[var(--brand-cta)] sm:text-[10px]"
            >
              SigV4
            </Badge>
          </div>
        </div>

        <div className="space-y-1.5 overflow-hidden p-2.5">
          <MockCredentialRow
            label="Endpoint"
            value="https://cloud.appwrite.io/v1/s3"
            delayMs={0}
          />
          <MockCredentialRow
            label="Access key"
            value="AKIA4EXAMPLEKEY"
            masked
            delayMs={40}
          />
          <MockCredentialRow
            label="Secret key"
            value="wJalrXUtnFEMIEXAMPLE"
            masked
            delayMs={80}
          />

          <div style={{ transitionDelay: '120ms' }}>
            <p className="mb-1.5 text-[9px] font-semibold uppercase tracking-wider text-muted-foreground sm:text-[10px]">
              Works with
            </p>
            <div className="grid grid-cols-2 gap-1.5">
              {COMPATIBLE_TOOLS.map((tool, index) => (
                <div
                  key={tool.id}
                  className={cn(
                    'flex min-w-0 items-center gap-1.5 rounded-md border border-border/70 px-2 py-1.5 transition-[border-color,background-color] duration-300',
                    productBentoContainer.panelMd,
                    'group-hover:border-[color-mix(in_srgb,var(--brand-cta)_18%,var(--border))] group-hover:bg-background motion-reduce:group-hover:border-border/70 motion-reduce:group-hover:bg-card/70',
                  )}
                  style={{ transitionDelay: `${120 + index * 30}ms` }}
                >
                  <span className="flex size-5 shrink-0 items-center justify-center rounded border border-border bg-muted/30">
                    <img src={tool.iconSrc} alt="" className="size-3" aria-hidden />
                  </span>
                  <span
                    className={cn(
                      'min-w-0 truncate text-[9px] font-medium sm:text-[10px]',
                      productBentoIdle.text,
                    )}
                  >
                    {tool.name}
                  </span>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
