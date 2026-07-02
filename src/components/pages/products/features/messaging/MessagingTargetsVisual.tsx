import { Bell, Mail, Phone, Smartphone } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

const TARGETS = [
  {
    id: '68a4e2f91b0c',
    label: 'walter@acme.io',
    type: 'email' as const,
    providerType: 'Email',
  },
  {
    id: '68a4d8c03f21',
    label: '+1 (555) 014-8921',
    type: 'sms' as const,
    providerType: 'SMS',
  },
  {
    id: '68a4c1aa7e55',
    label: 'iPhone 15 Pro',
    type: 'push' as const,
    providerType: 'Push',
  },
] as const

const TYPE_ICON = {
  email: Mail,
  sms: Phone,
  push: Bell,
} as const

export function MessagingTargetsVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'overview', label: t('Overview') },
        { id: 'sessions', label: t('Sessions') },
        { id: 'targets', label: t('Targets'), active: true },
      ]}
    >
      <div className="space-y-4">
        <div className="flex items-start justify-between gap-3 rounded-lg border border-border bg-muted/15 px-3 py-2.5">
          <div className="flex items-start gap-2.5">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-[11px] font-semibold text-muted-foreground">
              W
            </span>
            <div>
              <p className="text-[13px] font-semibold text-foreground">Walter O'Brien</p>
              <p className="text-[11px] text-muted-foreground">walter@acme.io · {t('Auth user')}</p>
            </div>
          </div>
          <Badge variant="info" className="shrink-0 text-[10px]">
            {t('3 targets')}
          </Badge>
        </div>

        <div className="overflow-hidden rounded-lg border border-border bg-card">
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className="px-3 py-2.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t('Target')}
                </TableHead>
                <TableHead className="px-3 py-2.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t('Provider type')}
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {TARGETS.map((target) => {
                const Icon = TYPE_ICON[target.type]
                return (
                  <TableRow
                    key={target.id}
                    className="border-b border-border transition-colors group-hover/visual:bg-muted/25"
                  >
                    <TableCell className="px-3 py-2.5">
                      <div className="flex items-center gap-2">
                        <Icon className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
                        <span className="text-[12px] text-foreground">{target.label}</span>
                      </div>
                    </TableCell>
                    <TableCell className="px-3 py-2.5">
                      <Badge variant="info" className="text-[10px]">
                        {t(target.providerType)}
                      </Badge>
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        </div>

        <div className="flex items-start gap-2 rounded-lg border border-dashed border-border bg-muted/15 px-3 py-2.5">
          <Smartphone className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" aria-hidden />
          <p className="text-[11px] leading-5 text-muted-foreground">
            {t('Targets are created when users verify email or phone in Auth, or when your app registers push device tokens.')}
          </p>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
