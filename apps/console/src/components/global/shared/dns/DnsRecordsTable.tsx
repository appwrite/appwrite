import { Fragment } from 'react'
import type { ProxyRuleRecord } from '@/lib/appwrite/proxy-rules'
import { Badge } from '@/components/ui/badge'
import { CopyableId } from '@/components/global/shared/CopyableId'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { useT } from '@/lib/i18n/translate'

/**
 * An apex record that names a host, as a CNAME does. Providers call it ALIAS,
 * ANAME or CNAME flattening and answer it with the host's addresses.
 */
export const ALIAS_TYPE = 'ALIAS or ANAME'

export type DnsRecord = {
  type: string
  name: string
  value: string
  ttl: number | null
  /** MX preference, shown when the table has a Priority column. */
  priority?: number
  badge?: string
  /** What DNS had for this record at the last check. */
  check?: ProxyRuleRecord
  /** The record type that already verifies the domain, when this one is an unused alternative. */
  notNeeded?: string
  /** A record of another type that answers the name instead of this missing one, such as a provider's URL redirect. */
  conflict?: ProxyRuleRecord
}

/** The type of one record, on one line. The ALIAS type reads as a phrase. */
export function RecordType({ type }: { type: string }) {
  const t = useT()
  return (
    <span className="whitespace-nowrap">
      {type === ALIAS_TYPE ? t('ALIAS or ANAME') : type}
    </span>
  )
}

export function DnsRecordsTable({
  records,
  showTtl,
  showPriority = false,
  compact = false,
  fullValues = false,
}: {
  records: DnsRecord[]
  showTtl: boolean
  showPriority?: boolean
  /** Hide the TTL column, e.g. in modals */
  compact?: boolean
  /** Show name and value in full, wrapping long ones, instead of cutting them with an ellipsis. */
  fullValues?: boolean
}) {
  const t = useT()
  const ttl = showTtl && !compact
  return (
    <Table>
      <TableHeader>
        <TableRow className="hover:bg-transparent border-b border-border">
          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
            {t('Type')}
          </TableHead>
          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
            {t('Name')}
          </TableHead>
          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
            {t('Value')}
          </TableHead>
          {showPriority && (
            <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
              {t('Priority')}
            </TableHead>
          )}
          {ttl && (
            <TableHead className="hidden sm:table-cell px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
              TTL
            </TableHead>
          )}
        </TableRow>
      </TableHeader>
      <TableBody>
        {records.map((r, i) => (
          <Fragment key={`${r.type}-${i}`}>
            <TableRow>
              <TableCell className="px-4 py-3 font-mono text-[12px]">
                <span className="inline-flex flex-wrap items-center gap-1.5">
                  <RecordType type={r.type} />
                  {r.badge ? (
                    <Badge
                      variant={
                        r.check?.required || r.badge === 'Required'
                          ? 'warning'
                          : 'success'
                      }
                      className="text-[10px] shrink-0 font-sans font-medium normal-case tracking-normal"
                    >
                      {t(r.badge)}
                    </Badge>
                  ) : null}
                </span>
              </TableCell>
              <TableCell
                className="px-4 py-3 font-mono text-[12px]"
                title={r.name || undefined}
              >
                {r.name ? (
                  <CopyableId
                    id={r.name}
                    variant="inline"
                    size="md"
                    maxWidth={180}
                    wrap={fullValues}
                    dir="ltr"
                    showCopyOnHover={false}
                    className="-my-1.5 -ms-2.5"
                  />
                ) : (
                  '-'
                )}
              </TableCell>
              <TableCell className="px-4 py-3" title={r.value}>
                <CopyableId
                  id={r.value}
                  variant="inline"
                  size="md"
                  maxWidth={200}
                  wrap={fullValues}
                  dir="ltr"
                  showCopyOnHover={false}
                  className="-my-1.5 -ms-2.5"
                />
              </TableCell>
              {showPriority && (
                <TableCell className="px-4 py-3 text-end text-[12px] text-muted-foreground">
                  {r.priority ?? '-'}
                </TableCell>
              )}
              {ttl && (
                <TableCell className="hidden sm:table-cell px-4 py-3 text-end text-[12px] text-muted-foreground">
                  {r.ttl ?? '-'}
                </TableCell>
              )}
            </TableRow>
          </Fragment>
        ))}
      </TableBody>
    </Table>
  )
}
