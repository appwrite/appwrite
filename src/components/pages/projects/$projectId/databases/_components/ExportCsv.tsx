/**
 * Export CSV modal: column selection, delimiter, header, optional filters.
 * Submit starts a CSV export migration; progress appears in CsvExportBox.
 */

import { useState, useMemo, useEffect, useRef } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  tableQueryOptions,
  tableColumnsQueryOptions,
  tableRowsQueryOptions,
  useCreateCSVExport,
} from '@/lib/react-query/hooks'
import { useSessionMigrations } from '@/components/global/providers/SessionMigrationsContext'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import type { DatabaseRouteKind } from '@/lib/database-routes'
import { getLocalizedDatabaseConsoleLabels } from '@/lib/database-console-labels'
import { buildCollectionExportColumnKeys } from '@/lib/databases/collection-indexable-attributes'

const DELIMITERS = [
  { value: ',', label: 'Comma' },
  { value: ';', label: 'Semicolon' },
  { value: '\t', label: 'Tab' },
  { value: '|', label: 'Pipe' },
] as const

const COLUMNS_VISIBLE_COLLAPSED = 6
/** Export may need more attributes than the spreadsheet first page. */
const EXPORT_COLUMNS_PAGE_SIZE = 1000
const EXPORT_SAMPLE_ROWS_LIMIT = 50

export interface ExportCsvProps {
  projectId: string
  databaseId: string
  tableId: string
  dbKind?: DatabaseRouteKind
  filterQueries?: string[]
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess?: () => void
}

function isCollectionDatabaseKind(
  dbKind: DatabaseRouteKind | undefined,
): boolean {
  return dbKind === 'documentsdb' || dbKind === 'vectorsdb'
}

export function ExportCsv({
  projectId,
  databaseId,
  tableId,
  dbKind,
  filterQueries,
  open,
  onOpenChange,
  onSuccess,
}: ExportCsvProps) {
  const t = useT()
  const resolvedDbKind = dbKind ?? 'tablesdb'
  const dbLabels = getLocalizedDatabaseConsoleLabels(t, resolvedDbKind)
  const isCollectionExport = isCollectionDatabaseKind(dbKind)
  // Fetch only while open. Always-on hooks with different limits (1000 / 50)
  // duplicated listColumns/listRows on every table switch vs the spreadsheet
  // cache (100 / 25). Sample rows are only needed for documents/vectors export.
  const dialogEnabled = open && !!projectId && !!databaseId && !!tableId
  const { data: table } = useQuery({
    ...tableQueryOptions(projectId, databaseId, resolvedDbKind, tableId),
    enabled: dialogEnabled,
  })
  const { data: columnsData } = useQuery({
    ...tableColumnsQueryOptions(
      projectId,
      databaseId,
      resolvedDbKind,
      tableId,
      undefined,
      0,
      EXPORT_COLUMNS_PAGE_SIZE,
    ),
    enabled: dialogEnabled,
  })
  const apiColumns = columnsData?.columns || []
  const { data: sampleRowsData } = useQuery({
    ...tableRowsQueryOptions(
      projectId,
      databaseId,
      tableId,
      resolvedDbKind,
      0,
      EXPORT_SAMPLE_ROWS_LIMIT,
    ),
    enabled: dialogEnabled && isCollectionExport,
  })
  const sampleDocumentRows = sampleRowsData?.rows || []
  const { addExportId } = useSessionMigrations(projectId)
  const createExport = useCreateCSVExport(projectId)

  const columnKeys = useMemo(() => {
    if (isCollectionExport) {
      return buildCollectionExportColumnKeys(
        apiColumns,
        sampleDocumentRows as Record<string, unknown>[],
      )
    }

    return (apiColumns || [])
      .map(
        (col: { key?: string; name?: string; $id?: string }) =>
          col.key || col.name || col.$id || '',
      )
      .filter(Boolean) as string[]
  }, [apiColumns, isCollectionExport, sampleDocumentRows])

  const [selectedColumns, setSelectedColumns] = useState<Set<string>>(new Set())
  const [delimiter, setDelimiter] = useState<string>(',')
  const [includeHeader, setIncludeHeader] = useState(true)
  const [exportWithFilters, setExportWithFilters] = useState(false)
  const [showMoreColumns, setShowMoreColumns] = useState(false)

  const hasInitializedColumns = useRef(false)
  useEffect(() => {
    hasInitializedColumns.current = false
    setSelectedColumns(new Set())
  }, [tableId])
  useEffect(() => {
    if (columnKeys.length > 0 && !hasInitializedColumns.current) {
      hasInitializedColumns.current = true
      setSelectedColumns(new Set(columnKeys))
    }
  }, [tableId, columnKeys, columnKeys.length])

  const hasActiveFilters = (filterQueries?.length ?? 0) > 0
  const applyFilters = exportWithFilters && hasActiveFilters
  const visibleCount = showMoreColumns
    ? columnKeys.length
    : Math.min(COLUMNS_VISIBLE_COLLAPSED, columnKeys.length)
  const visibleColumns = columnKeys.slice(0, visibleCount)
  const hasMoreColumns = columnKeys.length > COLUMNS_VISIBLE_COLLAPSED

  useEffect(() => {
    if (!hasActiveFilters && exportWithFilters) {
      setExportWithFilters(false)
    }
  }, [hasActiveFilters, exportWithFilters])

  const selectAll = () => setSelectedColumns(new Set(columnKeys))
  const deselectAll = () => setSelectedColumns(new Set())
  const toggleColumn = (key: string) => {
    const next = new Set(selectedColumns)
    if (next.has(key)) next.delete(key)
    else next.add(key)
    setSelectedColumns(next)
  }

  const canExport = selectedColumns.size > 0 && !createExport.isPending

  const handleExport = async () => {
    if (!canExport || !table?.name) return
    const filename = `${table.name.replace(/[^a-zA-Z0-9_-]/g, '_')}_${new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19)}.csv`
    try {
      const migration = await createExport.mutateAsync({
        databaseId,
        collectionId: tableId,
        filename,
        columns: Array.from(selectedColumns),
        queries: applyFilters ? (filterQueries ?? []) : [],
        delimiter,
        header: includeHeader,
        notify: true,
      })
      if (migration?.$id) addExportId(projectId, migration.$id)
      onOpenChange(false)
      onSuccess?.()
    } catch (e) {
      toast.error(
        e instanceof Error ? e.message : t('Failed to start CSV export'),
      )
    }
  }

  const handleOpenChange = (next: boolean) => {
    if (!next && !createExport.isPending) {
      onOpenChange(false)
    }
    if (next) {
      onOpenChange(true)
    }
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-xl max-h-[90dvh] flex flex-col p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start shrink-0">
          <DialogTitle>{t('Export CSV')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Choose columns and options for the export. The file will be prepared in the background and you can download it when ready.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border overflow-y-auto flex-1 min-h-0 px-6 py-4">
          <div className="space-y-6">
            {/* Columns */}
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-4 py-3">
                <h3 className="text-[15px] font-semibold text-foreground">
                  {dbLabels.schemaPluralTitle}
                </h3>
                <p className="text-[13px] text-muted-foreground mt-1">
                  {isCollectionExport
                    ? t('At least one attribute is required')
                    : t('At least one column is required.')}
                </p>
              </div>
              <div className="border-t border-border px-4 py-3">
                <div className="flex gap-2 mb-3">
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-8 text-[13px]"
                    onClick={selectAll}
                  >
                    {t('Select all')}
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-8 text-[13px]"
                    onClick={deselectAll}
                  >
                    {t('Deselect all')}
                  </Button>
                </div>
                <div className="flex flex-wrap gap-x-6 gap-y-2">
                  {visibleColumns.map((key) => (
                    <label
                      key={key}
                      className="flex items-center gap-2 cursor-pointer min-w-0 max-w-full"
                    >
                      <Checkbox
                        checked={selectedColumns.has(key)}
                        onCheckedChange={() => toggleColumn(key)}
                      />
                      <span className="text-[13px] truncate" title={key}>
                        {key}
                      </span>
                    </label>
                  ))}
                </div>
                {hasMoreColumns && (
                  <Button
                    variant="ghost"
                    size="sm"
                    className="mt-2 h-8 text-[13px] text-muted-foreground"
                    onClick={() => setShowMoreColumns(!showMoreColumns)}
                  >
                    {showMoreColumns ? t('Show less') : t('Show more')}
                  </Button>
                )}
              </div>
            </div>

            {/* Export options */}
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-4 py-3">
                <h3 className="text-[15px] font-semibold text-foreground">
                  {t('Export options')}
                </h3>
              </div>
              <div className="border-t border-border px-4 py-3 space-y-4">
                <div>
                  <TooltipProvider delayDuration={0}>
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <label className="text-[13px] font-medium text-foreground block mb-1.5 cursor-help">
                          {t('Delimiter')}
                        </label>
                      </TooltipTrigger>
                      <TooltipContent side="top">
                        <p className="max-w-[240px]">
                          {t('Define how to separate values in the exported file.')}
                        </p>
                      </TooltipContent>
                    </Tooltip>
                  </TooltipProvider>
                  <Select value={delimiter} onValueChange={setDelimiter}>
                    <SelectTrigger className="w-[180px] h-9">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {DELIMITERS.map((d) => (
                        <SelectItem key={d.value} value={d.value}>
                          {t(d.label)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <label className="flex items-start gap-2 cursor-pointer">
                  <Checkbox
                    checked={includeHeader}
                    onCheckedChange={(v) => setIncludeHeader(v === true)}
                    className="mt-0.5"
                  />
                  <span className="text-[13px] text-foreground">
                    {t('Include header row - Column names as the first row.')}
                  </span>
                </label>
                <label
                  className={`flex items-start gap-2 ${hasActiveFilters ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'}`}
                >
                  <Checkbox
                    checked={applyFilters}
                    onCheckedChange={(v) => setExportWithFilters(v === true)}
                    disabled={!hasActiveFilters}
                    className="mt-0.5"
                  />
                  <span className="text-[13px] text-foreground">
                    {t('Export with filters - Export rows matching current table filters.')}
                  </span>
                </label>
                {!hasActiveFilters && (
                  <p className="text-[12px] text-muted-foreground">
                    {t('No active filters on the table.')}
                  </p>
                )}
              </div>
            </div>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end shrink-0">
          <Button
            variant="outline"
            onClick={() => handleOpenChange(false)}
            disabled={createExport.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button onClick={handleExport} disabled={!canExport}>
            {t('Export')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
