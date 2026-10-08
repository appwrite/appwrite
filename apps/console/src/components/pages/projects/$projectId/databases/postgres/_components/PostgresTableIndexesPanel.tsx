import { useMemo, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger} from '@/components/ui/dropdown-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger} from '@/components/ui/tooltip'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { cn } from '@/lib/utils'
import {
  SPREADSHEET_FILLER_CELL_CLASS,
  SPREADSHEET_FILLER_HEADER_CLASS,
  SPREADSHEET_SCROLL_LAYER_CLASS,
} from '@/lib/layout/spreadsheet-sticky'
import {
  openDialogAfterOverlayCloses,
  closeDialogBeforeOverlayUnmount,
} from '@/lib/utils/overlay-lock'
import { useDatabaseTableOperationsAccess } from '../../_components/DatabaseOperationsLockContext'
import {
  useExecutePostgresSql,
  usePostgresTableIndexes} from '@/lib/react-query/hooks'
import { buildPostgresDropIndexSql } from '@/lib/postgres-table-ddl'
import { parsePostgresTableId } from '@/lib/postgres-database-routes'
import {
  formatPostgresIndexMetadataPreview,
  parsePostgresIndexIncludeColumns} from '@/lib/postgres-index-metadata'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  isPostgresPrimaryIndex,
  type PostgresTableIndexRow} from '@/lib/postgres-sql'
import { Key, Trash2 } from 'lucide-react'
import { PostgresTableIndexDrawer } from './PostgresTableIndexDrawer'
import { PostgresIndexContextMenu } from './PostgresIndexContextMenu'
import { useT } from '@/lib/i18n/translate'
import { localizePostgresIndexAlgorithmLabel } from '@/lib/i18n/resource-status-labels'
import {
  matchesPostgresLocalSearch,
  parsePostgresIndexColumnsFromDefinition,
  POSTGRES_ACTIONS_COL_PX,
  POSTGRES_ACTIONS_COL_STYLE,
  POSTGRES_BODY_CELL_BORDER_CLASS,
  POSTGRES_HEADER_CELL_BORDER_CLASS,
  POSTGRES_STICKY_ACTIONS_HEADER_CLASS,
  POSTGRES_STICKY_THEAD_CLASS,
  postgresStickyActionsCellClass,
} from './postgres-spreadsheet-chrome'

const POSTGRES_INDEXES_GRID_MIN_WIDTH_PX =
  200 + 100 + 90 + 180 + 140 + 120 + 140 + POSTGRES_ACTIONS_COL_PX

type PostgresTableIndexesPanelProps = {
  databaseId: string
  tableId: string
  search?: string
  createDialogOpen?: boolean
  onCreateDialogOpenChange?: (open: boolean) => void
}

function isTruthyFlag(value: boolean | string | undefined): boolean {
  return value === true || value === 'true' || value === 't'
}

function getPostgresIndexDisplayType(index: PostgresTableIndexRow): string {
  if (isPostgresPrimaryIndex(index)) return 'primary'
  if (isTruthyFlag(index.is_unique)) return 'unique'
  return 'key'
}

export function PostgresTableIndexesPanel({
  databaseId,
  tableId,
  search = '',
  createDialogOpen: createDialogOpenProp,
  onCreateDialogOpenChange}: PostgresTableIndexesPanelProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const { schema } = parsePostgresTableId(tableId)
  const { canWrite } = useDatabaseTableOperationsAccess()

  const { indexes, isLoading, refetch } = usePostgresTableIndexes(
    projectId,
    databaseId,
    tableId,
  )
  const executeSql = useExecutePostgresSql(projectId, databaseId)

  const [internalDialogOpen, setInternalDialogOpen] = useState(false)
  const dialogOpen = (createDialogOpenProp ?? false) || internalDialogOpen
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [indexToDelete, setIndexToDelete] = useState<string | null>(null)

  const setDialogOpen = (open: boolean) => {
    setInternalDialogOpen(open)
    onCreateDialogOpenChange?.(open)
  }

  const openDeleteDialog = (indexName: string) => {
    openDialogAfterOverlayCloses(() => {
      setIndexToDelete(indexName)
      setDeleteDialogOpen(true)
    })
  }

  const handleDelete = async () => {
    if (!indexToDelete) return
    const name = indexToDelete
    closeDialogBeforeOverlayUnmount(() => {
      setDeleteDialogOpen(false)
      setIndexToDelete(null)
    })
    try {
      await executeSql.mutateAsync(buildPostgresDropIndexSql(schema, name))
      toast.success(t('Index deleted'))
      await refetch()
    } catch (error) {
      toast.error(getErrorMessage(error) ?? t('Failed to delete index'))
    }
  }

  const filteredIndexes = useMemo(() => {
    return indexes.filter((index) => {
      const indexType = getPostgresIndexDisplayType(index)
      const indexColumns = parsePostgresIndexColumnsFromDefinition(
        index.index_definition,
      ).join(' ')
      const includeColumns =
        index.index_include ??
        parsePostgresIndexIncludeColumns(index.index_definition).join(', ')
      return matchesPostgresLocalSearch(
        search,
        index.index_name,
        indexType,
        indexColumns,
        index.index_definition,
        index.index_algorithm,
        index.index_condition,
        includeColumns,
        index.index_comment,
      )
    })
  }, [indexes, search])

  const hasSearch = search.trim().length > 0

  if (isLoading && indexes.length === 0) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-[13px] text-muted-foreground">
          {t('Loading indexes…')}
        </div>
      </div>
    )
  }

  if (hasSearch && filteredIndexes.length === 0) {
    return (
      <div className="flex h-full flex-col items-center justify-center px-6 py-12 text-center">
        <p className="text-[15px] font-medium text-foreground">
          {t('No indexes match your search')}
        </p>
        <p className="mt-2 max-w-sm text-[13px] text-muted-foreground">
          {t('Try adjusting or clearing your search.')}
        </p>
      </div>
    )
  }

  if (indexes.length === 0) {
    return (
      <>
        <div className="flex h-full flex-col items-center justify-center px-6 py-12 text-center">
          <p className="text-[15px] font-medium text-foreground">
            {t('No indexes')}
          </p>
          <p className="mt-2 max-w-sm text-[13px] text-muted-foreground">
            {t('Create an index to improve query performance on this table.')}
          </p>
          {canWrite ? (
            <Button
              size="sm"
              className="mt-4 h-9"
              onClick={() => setDialogOpen(true)}
            >
              {t('Create index')}
            </Button>
          ) : null}
        </div>
        <PostgresTableIndexDrawer
          open={dialogOpen}
          onOpenChange={setDialogOpen}
          projectId={projectId}
          databaseId={databaseId}
          tableId={tableId}
          onSuccess={() => void refetch()}
        />
      </>
    )
  }

  return (
    <>
      <div className="flex h-full flex-col relative">
        <div className="flex-1 overflow-auto overscroll-contain">
          <div
            className={SPREADSHEET_SCROLL_LAYER_CLASS}
            style={{ minWidth: POSTGRES_INDEXES_GRID_MIN_WIDTH_PX }}
          >
          <table className="w-full border-collapse">
            <thead className={POSTGRES_STICKY_THEAD_CLASS}>
              <tr>
                <th
                  className={cn(
                    'min-w-[200px] px-3 py-2 text-start',
                    POSTGRES_HEADER_CELL_BORDER_CLASS,
                  )}
                >
                  <span className="text-[12px] font-medium text-foreground">
                    {t('Key')}
                  </span>
                </th>
                <th
                  className={cn(
                    'min-w-[100px] px-3 py-2 text-start',
                    POSTGRES_HEADER_CELL_BORDER_CLASS,
                  )}
                >
                  <span className="text-[12px] font-medium text-foreground">
                    {t('Algorithm')}
                  </span>
                </th>
                <th
                  className={cn(
                    'min-w-[90px] px-3 py-2 text-start',
                    POSTGRES_HEADER_CELL_BORDER_CLASS,
                  )}
                >
                  <span className="text-[12px] font-medium text-foreground">
                    {t('Unique')}
                  </span>
                </th>
                <th
                  className={cn(
                    'min-w-[180px] px-3 py-2 text-start',
                    POSTGRES_HEADER_CELL_BORDER_CLASS,
                  )}
                >
                  <span className="text-[12px] font-medium text-foreground">
                    {t('Columns')}
                  </span>
                </th>
                <th
                  className={cn(
                    'min-w-[140px] px-3 py-2 text-start',
                    POSTGRES_HEADER_CELL_BORDER_CLASS,
                  )}
                >
                  <span className="text-[12px] font-medium text-foreground">
                    {t('Condition')}
                  </span>
                </th>
                <th
                  className={cn(
                    'min-w-[120px] px-3 py-2 text-start',
                    POSTGRES_HEADER_CELL_BORDER_CLASS,
                  )}
                >
                  <span className="text-[12px] font-medium text-foreground">
                    {t('Include')}
                  </span>
                </th>
                <th
                  className={cn(
                    'min-w-[140px] px-3 py-2 text-start',
                    POSTGRES_HEADER_CELL_BORDER_CLASS,
                  )}
                >
                  <span className="text-[12px] font-medium text-foreground">
                    {t('Comment')}
                  </span>
                </th>
                <th aria-hidden className={SPREADSHEET_FILLER_HEADER_CLASS} />
                <th
                  className={POSTGRES_STICKY_ACTIONS_HEADER_CLASS}
                  style={POSTGRES_ACTIONS_COL_STYLE}
                />
              </tr>
            </thead>
            <tbody>
              {filteredIndexes.map((index) => {
                const indexType = getPostgresIndexDisplayType(index)
                const isPrimary = indexType === 'primary'
                const indexColumns = parsePostgresIndexColumnsFromDefinition(
                  index.index_definition,
                )
                const includeColumns =
                  index.index_include ??
                  parsePostgresIndexIncludeColumns(index.index_definition).join(', ')
                const condition = index.index_condition?.trim() ?? ''
                const comment = index.index_comment?.trim() ?? ''
                const isUnique =
                  isTruthyFlag(index.is_unique) || isPostgresPrimaryIndex(index)

                return (
                  <PostgresIndexContextMenu
                    key={index.index_name}
                    index={index}
                    canWrite={canWrite && !isPrimary}
                    onDelete={openDeleteDialog}
                  >
                  <tr
                    className={cn(
                      'group transition-colors hover:bg-muted/50',
                      isPrimary && 'bg-muted/30',
                    )}
                  >
                    <td className={cn('px-3 py-2', POSTGRES_BODY_CELL_BORDER_CLASS)}>
                      <div className="flex items-center gap-2">
                        <Key className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                        <code
                          className={cn(
                            'font-mono text-[12px]',
                            isPrimary
                              ? 'text-muted-foreground'
                              : 'text-foreground',
                          )}
                        >
                          {index.index_name}
                        </code>
                        {isPrimary ? (
                          <Badge variant="info" className="text-[10px] shrink-0">
                            {t('Primary key')}
                          </Badge>
                        ) : null}
                      </div>
                    </td>
                    <td className={cn('px-3 py-2', POSTGRES_BODY_CELL_BORDER_CLASS)}>
                      <Badge variant="outline" className="text-[11px] font-medium">
                        {localizePostgresIndexAlgorithmLabel(
                          index.index_algorithm,
                          t,
                        )}
                      </Badge>
                    </td>
                    <td className={cn('px-3 py-2', POSTGRES_BODY_CELL_BORDER_CLASS)}>
                      <span className="text-[12px] text-foreground">
                        {isUnique ? 'TRUE' : 'FALSE'}
                      </span>
                    </td>
                    <td className={cn('px-3 py-2', POSTGRES_BODY_CELL_BORDER_CLASS)}>
                      <div className="flex flex-wrap gap-2">
                        {indexColumns.length > 0 ? (
                          indexColumns.map((columnName) => (
                            <code
                              key={columnName}
                              className="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground"
                            >
                              {columnName}
                            </code>
                          ))
                        ) : (
                          <span className="text-[12px] text-muted-foreground">
                            -
                          </span>
                        )}
                      </div>
                    </td>
                    <td className={cn('px-3 py-2', POSTGRES_BODY_CELL_BORDER_CLASS)}>
                      {condition ? (
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <code className="block max-w-[220px] truncate font-mono text-[11px] text-foreground">
                              {formatPostgresIndexMetadataPreview(condition, 40)}
                            </code>
                          </TooltipTrigger>
                          <TooltipContent side="top" className="max-w-sm">
                            <p className="font-mono text-[11px] break-all">
                              {condition}
                            </p>
                          </TooltipContent>
                        </Tooltip>
                      ) : (
                        <span className="text-[12px] text-muted-foreground">-</span>
                      )}
                    </td>
                    <td className={cn('px-3 py-2', POSTGRES_BODY_CELL_BORDER_CLASS)}>
                      {includeColumns ? (
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <code className="block max-w-[180px] truncate font-mono text-[11px] text-foreground">
                              {formatPostgresIndexMetadataPreview(includeColumns, 32)}
                            </code>
                          </TooltipTrigger>
                          <TooltipContent side="top" className="max-w-sm">
                            <p className="font-mono text-[11px] break-all">
                              {includeColumns}
                            </p>
                          </TooltipContent>
                        </Tooltip>
                      ) : (
                        <span className="text-[12px] text-muted-foreground">-</span>
                      )}
                    </td>
                    <td className={cn('px-3 py-2', POSTGRES_BODY_CELL_BORDER_CLASS)}>
                      {comment ? (
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <span className="block max-w-[220px] truncate text-[12px] text-foreground">
                              {formatPostgresIndexMetadataPreview(comment, 40)}
                            </span>
                          </TooltipTrigger>
                          <TooltipContent side="top" className="max-w-sm">
                            <p className="text-[12px] break-words">{comment}</p>
                          </TooltipContent>
                        </Tooltip>
                      ) : (
                        <span className="text-[12px] text-muted-foreground">-</span>
                      )}
                    </td>
                    <td aria-hidden className={SPREADSHEET_FILLER_CELL_CLASS} />
                    <td
                      className={postgresStickyActionsCellClass({
                        mutedRow: isPrimary,
                      })}
                      style={POSTGRES_ACTIONS_COL_STYLE}
                    >
                      <div
                        className="flex h-full items-center justify-center py-1.5"
                        style={POSTGRES_ACTIONS_COL_STYLE}
                      >
                      {canWrite && !isPrimary ? (
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <RowActionsMenuTrigger />
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end">
                            <DropdownMenuItem
                              onSelect={() => openDeleteDialog(index.index_name)}
                            >
                              <MenuItemContent icon={Trash2}>{t('Delete')}</MenuItemContent>
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      ) : null}
                      </div>
                    </td>
                  </tr>
                  </PostgresIndexContextMenu>
                )
              })}
            </tbody>
          </table>
          </div>
        </div>
      </div>

      <PostgresTableIndexDrawer
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        projectId={projectId}
        databaseId={databaseId}
        tableId={tableId}
        onSuccess={() => void refetch()}
      />

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete index')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete{' '}
              <strong>{indexToDelete}</strong>? This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={executeSql.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={() => void handleDelete()}
              disabled={executeSql.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
