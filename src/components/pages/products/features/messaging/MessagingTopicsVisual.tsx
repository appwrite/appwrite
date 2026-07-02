import { Hash, Users } from 'lucide-react'
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

const TOPICS = [
  {
    id: 'product-updates',
    name: 'Product updates',
    subscribers: 1248,
    useCase: 'Newsletters',
  },
  {
    id: 'security-alerts',
    name: 'Security alerts',
    subscribers: 892,
    useCase: 'Alerts',
  },
  {
    id: 'weekly-digest',
    name: 'Weekly digest',
    subscribers: 3401,
    useCase: 'Announcements',
  },
] as const

export function MessagingTopicsVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'messages', label: t('Messages') }, // pragma: allowlist secret
        { id: 'topics', label: t('Topics'), active: true },
        { id: 'providers', label: t('Providers') },
      ]}
    >
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <p className="text-[13px] font-semibold text-foreground">{t('Subscriber audiences')}</p>
            <p className="mt-0.5 text-[12px] text-muted-foreground">
              {t('Group targets for broadcasts without managing recipient lists.')}
            </p>
          </div>
          <Badge variant="info" className="shrink-0 text-[10px]">
            {t('3 topics')}
          </Badge>
        </div>

        <div className="overflow-hidden rounded-lg border border-border bg-card">
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className="px-3 py-2.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t('Topic')}
                </TableHead>
                <TableHead className="px-3 py-2.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t('Use case')}
                </TableHead>
                <TableHead className="px-3 py-2.5 text-end text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t('Subscribers')}
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {TOPICS.map((topic) => (
                <TableRow
                  key={topic.id}
                  className="border-b border-border transition-colors group-hover/visual:bg-muted/25"
                >
                  <TableCell className="px-3 py-2.5">
                    <div className="flex items-center gap-2">
                      <Hash className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
                      <span className="font-mono text-[11px] text-foreground">{topic.id}</span>
                    </div>
                  </TableCell>
                  <TableCell className="px-3 py-2.5">
                    <Badge variant="outline" className="text-[10px]">
                      {t(topic.useCase)}
                    </Badge>
                  </TableCell>
                  <TableCell className="px-3 py-2.5 text-end">
                    <span className="inline-flex items-center justify-end gap-1.5 text-[11px] text-foreground">
                      <Users className="size-3 text-muted-foreground" aria-hidden />
                      {topic.subscribers.toLocaleString()}
                    </span>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>

        <div className="flex items-start gap-2.5 rounded-lg border border-border bg-background/80 px-3 py-2.5">
          <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
            <Users className="size-3.5 text-muted-foreground" aria-hidden />
          </span>
          <p className="text-[11px] leading-5 text-muted-foreground">
            {t('Subscribe user targets from Auth or pick them in the Console when you create a topic.')}
          </p>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
