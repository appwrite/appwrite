import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { DATABASE_COMPUTE_CREDITS_NOTE } from '@/lib/database-create-pricing'
import { useT } from '@/lib/i18n/translate'
import { DEDICATED_DATABASE_PRICING_TIERS } from '@/lib/pricing/dedicated-databases'

const headClassName =
  'px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground sm:px-6'

export function PostgresSpecificationsTable() {
  const t = useT()

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/45 text-start">
      <div className="overflow-x-auto">
        <Table withScrollContainer={false} className="w-full min-w-[520px]">
          <TableHeader>
            <TableRow className="border-b border-border hover:bg-transparent">
              <TableHead className={headClassName}>{t('Tier')}</TableHead>
              <TableHead className={headClassName}>{t('CPU')}</TableHead>
              <TableHead className={headClassName}>{t('Memory')}</TableHead>
              <TableHead className={`${headClassName} text-end`}>
                {t('Price')}
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {DEDICATED_DATABASE_PRICING_TIERS.map((tier) => (
              <TableRow
                key={tier.id}
                className="border-b border-border hover:bg-transparent last:border-b-0"
              >
                <TableCell className="px-4 py-3 text-[13px] font-medium text-foreground sm:px-6">
                  {t(tier.label)}
                </TableCell>
                <TableCell className="px-4 py-3 text-[13px] text-muted-foreground sm:px-6">
                  {t(tier.cpu)}
                </TableCell>
                <TableCell className="px-4 py-3 text-[13px] text-muted-foreground sm:px-6">
                  {t(tier.memory)}
                </TableCell>
                <TableCell className="px-4 py-3 text-end text-[13px] font-medium text-foreground sm:px-6">
                  {t(tier.price)}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
      <div className="border-t border-border px-4 py-3 sm:px-6">
        <p className="text-[13px] leading-5 text-muted-foreground">
          {t(DATABASE_COMPUTE_CREDITS_NOTE)}
        </p>
      </div>
    </div>
  )
}
