import { useId, type ReactNode } from 'react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { cn } from '@/lib/utils'
import {
  isSpecificationAllowedInPlan,
  type SpecificationWithPlan,
} from '@/lib/specifications'
import { useT } from '@/lib/i18n/translate'

export type SpecificationTableRow = SpecificationWithPlan & {
  slug?: string
  cpus?: number
  memory?: number
}

type SpecificationTableCardProps = {
  title: string
  description: string
  /** Unique prefix for radio group names when multiple cards exist on one page */
  scope: 'build' | 'runtime-site' | 'runtime-function'
  specs: SpecificationTableRow[]
  selectedSlug: string
  onSelectedSlugChange: (slug: string) => void
  hasChanges: boolean
  isSaving: boolean
  onSave: () => void
  footerNote?: ReactNode
}

export function SpecificationTableCard({
  title,
  description,
  scope,
  specs,
  selectedSlug,
  onSelectedSlugChange,
  hasChanges,
  isSaving,
  onSave,
  footerNote,
}: SpecificationTableCardProps) {
  const t = useT()
  const radioGroupId = useId()
  const rows = specs.filter((s) => s.slug && String(s.slug).trim() !== '')
  const radioName = `spec-${scope}-${radioGroupId}`

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">{title}</h3>
        <p className="text-[13px] text-muted-foreground mt-1">{description}</p>
      </div>
      <div className="border-t border-border" />
      <div className="px-0 py-0 sm:px-0">
        <Table withScrollContainer={false}>
          <TableHeader>
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead className="w-[44px] px-4 py-3" />
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                {t('Identifier')}
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                vCPU
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                {t('Memory')}
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                {t('Availability')}
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((spec) => {
              const slug = spec.slug as string
              const allowed = isSpecificationAllowedInPlan(spec)
              const selected = selectedSlug === slug
              return (
                <TableRow
                  key={slug}
                  className={cn(
                    'cursor-pointer border-b border-border transition-colors',
                    selected && 'bg-primary/[0.06]',
                    !allowed &&
                      'cursor-not-allowed opacity-55 hover:bg-transparent',
                    allowed && 'hover:bg-muted/40',
                  )}
                  onClick={() => {
                    if (allowed) onSelectedSlugChange(slug)
                  }}
                >
                  <TableCell className="px-4 py-3 align-middle">
                    <input
                      type="radio"
                      name={radioName}
                      className="h-4 w-4 accent-primary"
                      checked={selected}
                      disabled={!allowed}
                      onChange={() => allowed && onSelectedSlugChange(slug)}
                      aria-label={`${t('Select specification')} ${slug}`}
                    />
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <span className="font-mono text-[12px] font-medium text-foreground tracking-tight">
                      {slug}
                    </span>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <span className="text-[13px] tabular-nums text-foreground">
                      {spec.cpus ?? '-'}
                    </span>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <span className="text-[13px] tabular-nums text-foreground">
                      {spec.memory != null ? `${spec.memory} MB` : '-'}
                    </span>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {allowed ? (
                      <Badge variant="success" className="text-[10px] shrink-0">
                        {t('Available')}
                      </Badge>
                    ) : (
                      <Badge variant="warning" className="text-[10px] shrink-0">
                        {t('Plan limit')}
                      </Badge>
                    )}
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      </div>
      {footerNote ? (
        <div className="border-t border-border px-6 py-3">{footerNote}</div>
      ) : null}
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || isSaving}
          onClick={onSave}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
