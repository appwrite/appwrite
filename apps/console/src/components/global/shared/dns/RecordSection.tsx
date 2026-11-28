import { Fragment, type ReactNode } from 'react'
import type { ProxyRuleRecordRegion } from '@/lib/appwrite/proxy-rules'
import { getLaggingRegions } from '@/lib/utils/status-badge'
import { getMisplacedName } from '@/lib/utils/proxy-domains'
import { Check, Clock, Info, X } from 'lucide-react'
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
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { useT } from '@/lib/i18n/translate'
import { ALIAS_TYPE, RecordType, type DnsRecord } from './DnsRecordsTable'

/** A section label, outlined so it never reads as a record status. */
export const SECTION_BADGE =
  'border border-border bg-transparent text-[10px] shrink-0 font-medium text-muted-foreground'

const TABLE_HEAD =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

/**
 * How a region's result reads in the region list. A region that is behind is
 * amber, not red: it only needs time. Red is for a lookup that failed.
 */
const REGION_STATUS: Record<
  ProxyRuleRecordRegion['status'],
  { Icon: typeof Check; className: string; label: string }
> = {
  verified: {
    Icon: Check,
    className: 'text-emerald-600 dark:text-emerald-400',
    label: 'Sees your record',
  },
  missing: {
    Icon: Clock,
    className: 'text-amber-600 dark:text-amber-400',
    label: 'Not there yet',
  },
  mismatch: {
    Icon: Clock,
    className: 'text-amber-600 dark:text-amber-400',
    label: 'Not there yet',
  },
  failed: {
    Icon: X,
    className: 'text-red-600 dark:text-red-400',
    label: 'Lookup failed',
  },
}

/** Each region and what it sees, for a record that is still propagating. */
function RegionList({ regions }: { regions: ProxyRuleRecordRegion[] }) {
  const t = useT()
  const muted = 'text-muted-foreground'
  const values = 'font-mono break-all text-foreground'
  return (
    <ul className="grid grid-cols-[auto_auto_minmax(0,1fr)] items-baseline gap-x-2.5 gap-y-1.5 text-start">
      {regions.map((region) => {
        // A status a newer server adds reads as not there yet
        const { Icon, className, label } =
          REGION_STATUS[region.status] ?? REGION_STATUS.missing
        let seen: ReactNode
        if (region.status === 'failed') {
          seen = <span className={muted}>{t('lookup failed')}</span>
        } else if (region.current.length === 0) {
          // An ALIAS keeps no values: they are addresses the developer never set.
          seen = (
            <span className={muted}>
              {region.status === 'verified'
                ? t('sees it')
                : t('not visible yet')}
            </span>
          )
        } else if (region.status === 'mismatch') {
          // Split around the value so each language places it in its order.
          const [before = '', after = ''] =
            t('still sees {value}').split('{value}')
          seen = (
            <>
              {before && <span className={muted}>{before}</span>}
              <span className={values} dir="ltr">
                {region.current.join(', ')}
              </span>
              {after && <span className={muted}>{after}</span>}
            </>
          )
        } else {
          seen = (
            <span className={values} dir="ltr">
              {region.current.join(', ')}
            </span>
          )
        }
        return (
          <li key={region.id} className="contents">
            <span className="text-foreground">{region.name}</span>
            <Icon
              className={`h-3.5 w-3.5 self-center ${className}`}
              role="img"
              aria-label={t(label)}
            />
            <span className="min-w-0">{seen}</span>
          </li>
        )
      })}
    </ul>
  )
}

/** The status pill of one record at the last check. */
function RecordStatus({
  record,
  waiting,
}: {
  record: DnsRecord
  /** Still inside the automatic check window. */
  waiting: boolean
}) {
  const t = useT()
  const { check, notNeeded } = record
  if (!check) return <span className="text-muted-foreground">-</span>
  const pill = 'text-[10px] shrink-0'
  if (check.status !== 'verified' && notNeeded) {
    return (
      <Badge
        variant="inactive"
        className={pill}
        title={t('Not needed. Your {type} record is set.').replace(
          '{type}',
          notNeeded,
        )}
      >
        {t('Not needed')}
      </Badge>
    )
  }
  // Some regions already see the record: it only needs time, so the pill
  // opens what each region sees instead of a status to act on.
  // Past the check window the record needs fixing, whatever some regions see
  if (
    waiting &&
    check.status !== 'verified' &&
    getLaggingRegions(check).length > 0
  ) {
    const regions = check.regions ?? []
    const seen = t('{n} of {total} regions see your record')
      .replace(
        '{n}',
        String(regions.filter((r) => r.status === 'verified').length),
      )
      .replace('{total}', String(regions.length))
    return (
      <Popover>
        <PopoverTrigger asChild>
          <button
            type="button"
            className="cursor-pointer rounded-md focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
            aria-label={`${t('Propagating')}: ${seen}`}
          >
            <Badge variant="warning" className={pill}>
              {t('Propagating')}
              <Info aria-hidden />
            </Badge>
          </button>
        </PopoverTrigger>
        <PopoverContent align="end" className="w-auto max-w-xs p-3 text-xs">
          <p className="mb-2 font-medium text-foreground">{seen}</p>
          <RegionList regions={regions} />
        </PopoverContent>
      </Popover>
    )
  }
  const title = check.message || undefined
  switch (check.status) {
    case 'verified':
      return (
        <Badge variant="success" className={pill}>
          {t('Verified')}
        </Badge>
      )
    case 'missing':
      if (record.conflict) {
        return (
          <Badge
            variant="error"
            className={pill}
            title={record.conflict.message || undefined}
          >
            {t('Wrong type')}
          </Badge>
        )
      }
      return (
        <Badge variant="info" className={pill} title={title}>
          {waiting ? t('Not detected yet') : t('Not detected')}
        </Badge>
      )
    case 'mismatch':
      return (
        <Badge variant="error" className={pill} title={title}>
          {check.type === 'CAA' ? t('Blocks certificate') : t('Wrong value')}
        </Badge>
      )
    case 'misplaced':
      return (
        <Badge variant="error" className={pill} title={title}>
          {t('Wrong name')}
        </Badge>
      )
    default:
      return (
        <Badge variant="warning" className={pill} title={title}>
          {t("Couldn't check")}
        </Badge>
      )
  }
}

/**
 * What DNS returned for a record, on one line: the first value, then all of
 * them in a popover, as IPv6 addresses would wrap the line. A popover opens
 * on tap too, where a tooltip would not.
 */
function FoundValues({
  values,
  wrong,
  label,
}: {
  values: string[]
  /** Underline the value as the one to change. */
  wrong: boolean
  /** Lead-in before the values, `Found:` by default. */
  label?: string
}) {
  const t = useT()
  const [first, ...rest] = values
  return (
    <p className="flex min-w-0 items-baseline gap-1.5 text-[12px] text-muted-foreground">
      <span className="shrink-0">{label ?? t('Found:')}</span>
      <span
        className={`min-w-0 truncate pb-1 font-mono text-foreground ${wrong ? 'underline decoration-wavy decoration-red-500 underline-offset-2' : ''}`}
        title={first}
      >
        {first}
      </span>
      {rest.length > 0 && (
        <Popover>
          <PopoverTrigger asChild>
            <button
              type="button"
              className="shrink-0 cursor-pointer rounded-sm whitespace-nowrap underline decoration-dotted underline-offset-2 hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              {t('and {n} more').replace('{n}', String(rest.length))}
            </button>
          </PopoverTrigger>
          <PopoverContent align="start" className="w-auto max-w-sm p-3 text-xs">
            <ul className="space-y-0.5 font-mono">
              {values.map((value) => (
                <li key={value} className="break-all">
                  {value}
                </li>
              ))}
            </ul>
          </PopoverContent>
        </Popover>
      )}
    </p>
  )
}

/** One group of records: a heading, a badge, then the records with their status. */
export function RecordSection({
  title,
  badge,
  records,
  waiting,
  children,
}: {
  title: string
  badge: string
  records: DnsRecord[]
  waiting: boolean
  children?: ReactNode
}) {
  const t = useT()
  // The ALIAS type takes three words, so its column is wider.
  const typeWidth = records.some((r) => r.type === ALIAS_TYPE)
    ? 'w-[160px]'
    : 'w-[88px]'
  return (
    <section className="space-y-3">
      <div className="flex items-center gap-2">
        <h4 className="text-[14px] font-semibold text-foreground">{title}</h4>
        <Badge variant="outline" className={SECTION_BADGE}>
          {badge}
        </Badge>
      </div>
      <div className="overflow-hidden rounded-lg border border-border">
        <Table className="table-fixed">
          <TableHeader>
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead className={`${typeWidth} ${TABLE_HEAD}`}>
                {t('Type')}
              </TableHead>
              <TableHead className={`w-[22%] ${TABLE_HEAD}`}>
                {t('Name')}
              </TableHead>
              <TableHead className={TABLE_HEAD}>{t('Value')}</TableHead>
              <TableHead
                className={`hidden sm:table-cell w-[72px] ${TABLE_HEAD}`}
              >
                TTL
              </TableHead>
              <TableHead className={`w-[156px] ${TABLE_HEAD} text-end`}>
                {t('Status')}
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {records.map((r) => {
              // A misplaced record is wrong where it is, not in its value.
              const misplaced =
                r.check?.status === 'misplaced' &&
                !r.notNeeded &&
                getLaggingRegions(r.check).length === 0
              // Another record type answers where this one is missing.
              const conflict =
                r.check?.status === 'missing' && !r.notNeeded
                  ? r.conflict
                  : undefined
              // A propagating record shows what each region sees in its pill.
              const propagating =
                !!r.check && getLaggingRegions(r.check).length > 0
              const found =
                r.check?.status === 'mismatch' && !r.notNeeded && !propagating
                  ? r.check.current
                  : misplaced && r.check
                    ? [getMisplacedName(r.check.name)]
                    : (conflict?.current ?? [])
              return (
                <Fragment key={`${r.type}-${r.value}`}>
                  <TableRow className={found.length ? 'border-b-0' : undefined}>
                    <TableCell className="px-4 py-3 font-mono text-[12px]">
                      <RecordType type={r.type} />
                    </TableCell>
                    <TableCell
                      className="px-4 py-3 font-mono text-[12px]"
                      title={r.name || undefined}
                    >
                      <CopyableId
                        id={r.name}
                        variant="inline"
                        size="md"
                        constrainToContainer
                        showCopyOnHover={false}
                        className="-my-1.5 -ms-2.5"
                      />
                    </TableCell>
                    <TableCell className="px-4 py-3" title={r.value}>
                      <CopyableId
                        id={r.value}
                        variant="inline"
                        size="md"
                        constrainToContainer
                        showCopyOnHover={false}
                        className="-my-1.5 -ms-2.5"
                      />
                    </TableCell>
                    <TableCell className="hidden sm:table-cell px-4 py-3 text-[12px] text-muted-foreground">
                      {r.ttl ?? '-'}
                    </TableCell>
                    <TableCell className="px-4 py-3 text-end">
                      <RecordStatus record={r} waiting={waiting} />
                    </TableCell>
                  </TableRow>
                  {found.length > 0 && (
                    <TableRow className="hover:bg-transparent">
                      <TableCell colSpan={2} className="px-4 pt-0 pb-3" />
                      <TableCell colSpan={3} className="px-4 pt-0 pb-3">
                        <FoundValues
                          values={found}
                          wrong={r.type !== 'CAA'}
                          label={
                            misplaced
                              ? t('Found at:')
                              : conflict
                                ? t('Found {type}:').replace(
                                    '{type}',
                                    conflict.type,
                                  )
                                : undefined
                          }
                        />
                      </TableCell>
                    </TableRow>
                  )}
                </Fragment>
              )
            })}
          </TableBody>
        </Table>
      </div>
      {children}
    </section>
  )
}
