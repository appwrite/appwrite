import { useEffect, useMemo, useState } from 'react'
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
import { getColumnIcon } from '@/lib/utils/column-icons'
import { canShowTableSecuritySettings } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes } from '@/lib/react-query/hooks/organizations'
import { useProject } from '@/lib/react-query/hooks/projects'
import {
  useExecutePostgresSql,
  usePostgresTableColumns} from '@/lib/react-query/hooks'
import {
  buildPostgresDropColumnSql,
  formatPostgresColumnType} from '@/lib/postgres-table-ddl'
import {
  formatPostgresColumnMetadataPreview,
  getPostgresColumnCheckDisplay,
  getPostgresColumnForeignKeyDisplay} from '@/lib/postgres-column-metadata'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  isPostgresPrimaryKeyColumn,
  type PostgresTableColumnRow} from '@/lib/postgres-sql'
import { CheckCircle2, Copy, Pencil, Trash2 } from 'lucide-react'
import { PostgresTableColumnDrawer } from './PostgresTableColumnDrawer'
import { PostgresColumnContextMenu } from './PostgresColumnContextMenu'
import {
  getPostgresColumnTypeColor,
  matchesPostgresLocalSearch,
  POSTGRES_BODY_CELL_BORDER_CLASS,
  POSTGRES_HEADER_CELL_BORDER_CLASS,
  POSTGRES_LAST_CELL_BORDER_CLASS,
  POSTGRES_STICKY_THEAD_CLASS} from './postgres-spreadsheet-chrome'

type PostgresTableColumnsPanelProps = {
  databaseId: string
  tableId: string
  search?: string
  createDialogOpen?: boolean
  onCreateDialogOpenChange?: (open: boolean) => void
}

export function PostgresTableColumnsPanel({
  databaseId,
  tableId,
  search = '',
  createDialogOpen: createDialogOpenProp,
  onCreateDialogOpenChange}: PostgresTableColumnsPanelProps) {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId ?? undefined)
  const canWrite = canShowTableSecuritySettings(access, features)

  const { columns, isLoading, refetch } = usePostgresTableColumns(
    projectId,
    databaseId,
    tableId,
  )
  const executeSql = useExecutePostgresSql(projectId, databaseId)

  const [internalDialogOpen, setInternalDialogOpen] = useState(false)
  const dialogOpen = (createDialogOpenProp ?? false) || internalDialogOpen
  const [selectedColumn, setSelectedColumn] =
    useState<PostgresTableColumnRow | null>(null)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [columnToDelete, setColumnToDelete] = useState<string | null>(null)

  useEffect(() => {
    if (createDialogOpenProp) {
      setSelectedColumn(null)
    }
  }, [createDialogOpenProp])

  const closeDialog = () => {
    setInternalDialogOpen(false)
    onCreateDialogOpenChange?.(false)
    setSelectedColumn(null)
  }

  const handleCreate = () => {
    setSelectedColumn(null)
    setInternalDialogOpen(true)
    onCreateDialogOpenChange?.(true)
  }

  const handleEdit = (column: PostgresTableColumnRow) => {
    setSelectedColumn(column)
    setInternalDialogOpen(true)
  }

  const handleDelete = async () => {
    if (!columnToDelete) return
    try {
      await executeSql.mutateAsync(
        buildPostgresDropColumnSql(tableId, columnToDelete),
      )
      toast.success('Column deleted')
      setDeleteDialogOpen(false)
      setColumnToDelete(null)
      await refetch()
    } catch (error) {
      toast.error(getErrorMessage(error) ?? 'Failed to delete column')
    }
  }

  const isPrimaryKey = (column: PostgresTableColumnRow) =>
    isPostgresPrimaryKeyColumn(column)

  const filteredColumns = useMemo(() => {
    return columns.filter((column) => {
      const typeLabel = formatPostgresColumnType(column)
      return matchesPostgresLocalSearch(
        search,
        column.column_name,
        column.data_type,
        column.udt_name,
        typeLabel,
        column.column_default,
        column.column_comment,
        getPostgresColumnCheckDisplay(column.check_constraints),
        getPostgresColumnForeignKeyDisplay(column.foreign_keys),
      )
    })
  }, [columns, search])

  const hasSearch = search.trim().length > 0

  if (isLoading && columns.length === 0) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-[13px] text-muted-foreground">
          Loading columns…
        </div>
      </div>
    )
  }

  if (hasSearch && filteredColumns.length === 0) {
    return (
      <div className="flex h-full flex-col items-center justify-center px-6 py-12 text-center">
        <p className="text-[15px] font-medium text-foreground">
          No columns match your search
        </p>
        <p className="mt-2 max-w-sm text-[13px] text-muted-foreground">
          Try adjusting or clearing your search.
        </p>
      </div>
    )
  }

  if (columns.length === 0) {
    return (
      <>
        <div className="flex h-full flex-col items-center justify-center px-6 py-12 text-center">
          <p className="text-[15px] font-medium text-foreground">
            No columns
          </p>
          <p className="mt-2 max-w-sm text-[13px] text-muted-foreground">
            Add your first column to define this table&apos;s structure.
          </p>
          {canWrite ? (
            <Button size="sm" className="mt-4 h-9" onClick={handleCreate}>
              Add column
            </Button>
          ) : null}
        </div>
        <PostgresTableColumnDrawer
          open={dialogOpen}
          onOpenChange={(open) => {
            if (!open) closeDialog()
          }}
          projectId={projectId}
          databaseId={databaseId}
          tableId={tableId}
          column={selectedColumn}
          onSuccess={() => void refetch()}
        />
      </>
    )
  }

  return (
    <>
      <div className="flex h-full flex-col relative">
        <div className="flex-1 overflow-auto overscroll-contain">
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
                    Key
                  </span>
                </th>
                <th
                  className={cn(
                    'min-w-[120px] px-3 py-2 text-start',
                    POSTGRES_HEADER_CELL_BORDER_CLASS,
                  )}
                >
                  <span className="text-[12px] font-medium text-foreground">
                    Type
                  </span>
                </th>
                <th
                  className={cn(
                    'min-w-[80px] px-3 py-2 text-start',
                    POSTGRES_HEADER_CELL_BORDER_CLASS,
                  )}
                >
                  <span className="text-[12px] font-medium text-foreground">
                    Required
                  </span>
                </th>
                <th
                  className={cn(
                    'min-w-[120px] px-3 py-2 text-start',
                    POSTGRES_HEADER_CELL_BORDER_CLASS,
                  )}
                >
                  <span className="text-[12px] font-medium text-foreground">
                    Default
                  </span>
                </th>
                <th
                  className={cn(
                    'min-w-[140px] px-3 py-2 text-start',
                    POSTGRES_HEADER_CELL_BORDER_CLASS,
                  )}
                >
                  <span className="text-[12px] font-medium text-foreground">
                    Check
                  </span>
                </th>
                <th
                  className={cn(
                    'min-w-[140px] px-3 py-2 text-start',
                    POSTGRES_HEADER_CELL_BORDER_CLASS,
                  )}
                >
                  <span className="text-[12px] font-medium text-foreground">
                    Foreign key
                  </span>
                </th>
                <th
                  className={cn(
                    'min-w-[140px] px-3 py-2 text-start',
                    POSTGRES_HEADER_CELL_BORDER_CLASS,
                  )}
                >
                  <span className="text-[12px] font-medium text-foreground">
                    Comment
                  </span>
                </th>
                <th
                  className={cn(
                    'w-10 px-2 py-2 bg-background',
                    'shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border)]',
                  )}
                />
              </tr>
            </thead>
            <tbody>
              {filteredColumns.map((column) => {
                const Icon = getColumnIcon(column.udt_name || column.data_type)
                const typeLabel = formatPostgresColumnType(column)
                const typeKey = column.udt_name || column.data_type
                const required = column.is_nullable !== 'YES'
                const primary = isPrimaryKey(column)
                const checkDisplay = getPostgresColumnCheckDisplay(
                  column.check_constraints,
                )
                const foreignKeyDisplay = getPostgresColumnForeignKeyDisplay(
                  column.foreign_keys,
                )
                const commentDisplay = column.column_comment?.trim() ?? ''

                return (
                  <PostgresColumnContextMenu
                    key={column.column_name}
                    column={column}
                    isPrimary={primary}
                    canWrite={canWrite}
                    onUpdate={handleEdit}
                    onDelete={
                      !primary
                        ? (columnName) => {
                            setColumnToDelete(columnName)
                            setDeleteDialogOpen(true)
                          }
                        : undefined
                    }
                  >
                  <tr
                    className="group transition-colors hover:bg-muted/50"
                  >
                    <td className={cn('px-3 py-2', POSTGRES_BODY_CELL_BORDER_CLASS)}>
                      <div className="flex items-center gap-2">
                        <Icon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                        <code className="font-mono text-[12px] text-foreground">
                          {column.column_name}
                        </code>
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <button
                              type="button"
                              onClick={(event) => {
                                event.stopPropagation()
                                void navigator.clipboard.writeText(
                                  column.column_name,
                                )
                                toast.success('Column name copied')
                              }}
                              className="flex h-6 w-6 shrink-0 items-center justify-center rounded text-muted-foreground opacity-0 transition-opacity hover:bg-muted hover:text-foreground group-hover:opacity-100"
                            >
                              <Copy className="h-3 w-3" />
                            </button>
                          </TooltipTrigger>
                          <TooltipContent side="top">
                            <p>Copy column name</p>
                          </TooltipContent>
                        </Tooltip>
                        {primary ? (
                          <Badge variant="info" className="text-[10px] shrink-0">
                            Primary key
                          </Badge>
                        ) : null}
                      </div>
                    </td>
                    <td className={cn('px-3 py-2', POSTGRES_BODY_CELL_BORDER_CLASS)}>
                      <Badge
                        variant="outline"
                        className={cn(
                          'text-[11px] font-medium border',
                          getPostgresColumnTypeColor(typeKey),
                        )}
                      >
                        {typeLabel}
                      </Badge>
                    </td>
                    <td className={cn('px-3 py-2', POSTGRES_BODY_CELL_BORDER_CLASS)}>
                      {required ? (
                        <CheckCircle2 className="h-4 w-4 text-emerald-500" />
                      ) : (
                        <span className="text-[12px] text-muted-foreground">
                          -
                        </span>
                      )}
                    </td>
                    <td className={cn('px-3 py-2', POSTGRES_BODY_CELL_BORDER_CLASS)}>
                      <code className="font-mono text-[11px] text-muted-foreground">
                        {column.column_default ?? 'NULL'}
                      </code>
                    </td>
                    <td className={cn('px-3 py-2', POSTGRES_BODY_CELL_BORDER_CLASS)}>
                      {checkDisplay ? (
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <code className="block max-w-[220px] truncate font-mono text-[11px] text-foreground">
                              {formatPostgresColumnMetadataPreview(checkDisplay, 40)}
                            </code>
                          </TooltipTrigger>
                          <TooltipContent side="top" className="max-w-sm">
                            <p className="font-mono text-[11px] break-all">
                              {checkDisplay}
                            </p>
                          </TooltipContent>
                        </Tooltip>
                      ) : (
                        <span className="text-[12px] text-muted-foreground">-</span>
                      )}
                    </td>
                    <td className={cn('px-3 py-2', POSTGRES_BODY_CELL_BORDER_CLASS)}>
                      {foreignKeyDisplay ? (
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <code className="block max-w-[220px] truncate font-mono text-[11px] text-foreground">
                              {formatPostgresColumnMetadataPreview(
                                foreignKeyDisplay,
                                40,
                              )}
                            </code>
                          </TooltipTrigger>
                          <TooltipContent side="top" className="max-w-sm">
                            <p className="font-mono text-[11px] break-all">
                              {foreignKeyDisplay}
                            </p>
                          </TooltipContent>
                        </Tooltip>
                      ) : (
                        <span className="text-[12px] text-muted-foreground">-</span>
                      )}
                    </td>
                    <td className={cn('px-3 py-2', POSTGRES_BODY_CELL_BORDER_CLASS)}>
                      {commentDisplay ? (
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <span className="block max-w-[220px] truncate text-[12px] text-foreground">
                              {formatPostgresColumnMetadataPreview(commentDisplay, 40)}
                            </span>
                          </TooltipTrigger>
                          <TooltipContent side="top" className="max-w-sm">
                            <p className="text-[12px] break-words">{commentDisplay}</p>
                          </TooltipContent>
                        </Tooltip>
                      ) : (
                        <span className="text-[12px] text-muted-foreground">-</span>
                      )}
                    </td>
                    <td className={cn('px-2 py-2', POSTGRES_LAST_CELL_BORDER_CLASS)}>
                      {canWrite && !primary ? (
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <RowActionsMenuTrigger revealOnGroupHover />
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end">
                            <DropdownMenuItem onSelect={() => handleEdit(column)}>
                              <MenuItemContent icon={Pencil}>Update</MenuItemContent>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                              onSelect={() => {
                                setColumnToDelete(column.column_name)
                                setDeleteDialogOpen(true)
                              }}
                            >
                              <MenuItemContent icon={Trash2}>Delete</MenuItemContent>
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      ) : canWrite && primary ? (
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <RowActionsMenuTrigger revealOnGroupHover />
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end">
                            <DropdownMenuItem onSelect={() => handleEdit(column)}>
                              <MenuItemContent icon={Pencil}>Update</MenuItemContent>
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      ) : null}
                    </td>
                  </tr>
                  </PostgresColumnContextMenu>
                )
              })}
            </tbody>
          </table>
        </div>
      </div>

      <PostgresTableColumnDrawer
        open={dialogOpen}
        onOpenChange={(open) => {
          if (!open) closeDialog()
        }}
        projectId={projectId}
        databaseId={databaseId}
        tableId={tableId}
        column={selectedColumn}
        onSuccess={() => void refetch()}
      />

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>Delete column</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete{' '}
              <strong>{columnToDelete}</strong>? This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={executeSql.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={() => void handleDelete()}
              disabled={executeSql.isPending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
