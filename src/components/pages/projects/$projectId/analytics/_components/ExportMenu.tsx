import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { AnalyticsDimension, type Models } from '@appwrite.io/console'
import { Download, FileArchive, FileText, Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useT } from '@/lib/i18n/translate'
import { useCountryLookups } from '@/lib/react-query/hooks'
import type {
  AnalyticsChartInterval,
  AnalyticsRange,
} from '@/lib/react-query/hooks'
import { resolveCountryDisplayName } from '@/lib/locale/country-lookups'
import { buildFilterTagFromCompactKey } from '@/lib/table-filters'
import { ANALYTICS_FILTER_COLUMNS } from '@/lib/analytics/analytics-filters'
import { collectAnalyticsExport } from '@/lib/analytics/export/collect'
import { buildCsvEntries, slugify } from '@/lib/analytics/export/csv'
import {
  buildTarGz,
  downloadBlob,
  isGzipSupported,
} from '@/lib/analytics/export/archive'
import { buildAnalyticsReportPdf } from '@/lib/analytics/export/report-pdf'
import { loadReportFonts } from '@/lib/analytics/export/report-fonts'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useAnalyticsFilters } from './analytics-filters-context'

type ExportMenuProps = {
  projectId: string
  property: Models.AnalyticsProperty | undefined
  range: AnalyticsRange
  interval: AnalyticsChartInterval
  comparisonRange: AnalyticsRange | null
  compareLabel: string | null
}

type ExportKind = 'csv' | 'pdf'

/**
 * Export the current view (same range, interval, comparison and filters):
 * - Data: a `.tar.gz` with a README, summary, time series and one CSV per
 *   dimension.
 * - Report: a branded, multi-page PDF.
 */
export function ExportMenu({
  projectId,
  property,
  range,
  interval,
  comparisonRange,
  compareLabel,
}: ExportMenuProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const { filters, filterMap } = useAnalyticsFilters()
  const { lookups: countryLookups } = useCountryLookups()
  const [running, setRunning] = useState<ExportKind | null>(null)

  const formatLabel = (dimension: AnalyticsDimension, value: string) =>
    dimension === AnalyticsDimension.Country && countryLookups
      ? resolveCountryDisplayName(value, countryLookups)
      : value

  const filterLabels = Array.from(filterMap.keys()).map((key) => {
    const displayKey =
      key.c === 'country' && typeof key.v === 'string' && countryLookups
        ? { ...key, v: resolveCountryDisplayName(key.v, countryLookups) }
        : key
    return buildFilterTagFromCompactKey(displayKey, ANALYTICS_FILTER_COLUMNS).tag.replace(
      /\*\*/g,
      '',
    )
  })

  const run = async (kind: ExportKind) => {
    if (!property || running) return
    if (kind === 'csv' && !isGzipSupported()) {
      toast.error(t('Your browser does not support creating compressed archives.'))
      return
    }
    setRunning(kind)
    const toastId = toast.loading(
      kind === 'csv' ? t('Preparing data export...') : t('Preparing report...'),
    )
    try {
      const data = await collectAnalyticsExport(queryClient, {
        projectId,
        property,
        range,
        interval,
        comparisonRange,
        compareLabel,
        filters,
        filterLabels,
        onProgress: (done, total) =>
          toast.loading(
            `${kind === 'csv' ? t('Preparing data export...') : t('Preparing report...')} ${Math.round((done / total) * 100)}%`,
            { id: toastId },
          ),
      })

      const stamp = `${range.startAt.slice(0, 10)}_${range.endAt.slice(0, 10)}`
      // Capped so file and folder names stay readable; the archive itself
      // handles any path length (PAX headers).
      const slug = slugify(property.name).slice(0, 40).replace(/-+$/, '')
      const base = `appwrite-analytics_${slug || 'property'}_${stamp}`

      if (kind === 'csv') {
        // Country codes become names in the CSVs too.
        const named = {
          ...data,
          breakdowns: data.breakdowns.map((breakdown) => ({
            ...breakdown,
            rows: breakdown.rows.map((row) =>
              row.value
                ? { ...row, value: formatLabel(breakdown.dimension, row.value) }
                : row,
            ),
          })),
        }
        const blob = await buildTarGz(buildCsvEntries(named, base))
        downloadBlob(blob, `${base}.tar.gz`)
      } else {
        const fonts = await loadReportFonts()
        const blob = await buildAnalyticsReportPdf(data, formatLabel, fonts)
        downloadBlob(blob, `${base}.pdf`)
      }

      const skipped = data.breakdowns.filter((breakdown) => breakdown.skipped)
      toast.success(
        kind === 'csv' ? t('Data export downloaded') : t('Report downloaded'),
        {
          id: toastId,
          description: skipped.length
            ? `${skipped.length} ${t('breakdowns were not included. See the README for details.')}`
            : undefined,
        },
      )
    } catch (error) {
      toast.error(t('Export failed'), {
        id: toastId,
        description: getErrorMessage(error),
      })
    } finally {
      setRunning(null)
    }
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="outline"
          size="sm"
          className="h-9 gap-1.5 border-border bg-transparent text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground"
          disabled={!property || !!running}
          aria-label={t('Export')}
        >
          {running ? (
            <Loader2 className="h-3.5 w-3.5 animate-spin" />
          ) : (
            <Download className="h-3.5 w-3.5" />
          )}
          <span className="hidden @[760px]:inline">{t('Export')}</span>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-[260px]">
        <DropdownMenuLabel className="text-[11px] font-normal text-muted-foreground">
          {t('Uses the current date range, filters and comparison')}
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem
          onClick={() => void run('csv')}
          className="cursor-pointer items-start gap-2.5 py-2"
        >
          <FileArchive className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
          <span className="flex flex-col">
            <span className="text-[13px] text-foreground">{t('CSV data')}</span>
            <span className="text-[11px] text-muted-foreground">
              {t('Summary, time series and all breakdowns (.tar.gz)')}
            </span>
          </span>
        </DropdownMenuItem>
        <DropdownMenuItem
          onClick={() => void run('pdf')}
          className="cursor-pointer items-start gap-2.5 py-2"
        >
          <FileText className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
          <span className="flex flex-col">
            <span className="text-[13px] text-foreground">{t('PDF report')}</span>
            <span className="text-[11px] text-muted-foreground">
              {t('Key metrics, trends and top breakdowns')}
            </span>
          </span>
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
