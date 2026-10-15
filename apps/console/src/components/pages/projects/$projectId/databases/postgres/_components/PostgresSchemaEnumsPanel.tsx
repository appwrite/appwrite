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
  DialogTitle,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { EmptyState } from '@/components/global/shared/EmptyState'
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
  usePostgresSchemaEnums,
} from '@/lib/react-query/hooks'
import { buildPostgresDropEnumSql } from '@/lib/postgres-enum-ddl'
import {
  isPostgresEnumUsedInSchema,
  type PostgresSchemaEnumRow,
} from '@/lib/postgres-sql'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { ListOrdered, Pencil, Trash2 } from 'lucide-react'
import { PostgresSchemaEnumDrawer } from './PostgresSchemaEnumDrawer'
import { PostgresEnumContextMenu } from './PostgresEnumContextMenu'
import { useT } from '@/lib/i18n/translate'
import {
  getPostgresEnumValueBadgeClass,
  matchesPostgresLocalSearch,
  POSTGRES_ACTIONS_COL_PX,
  POSTGRES_ACTIONS_COL_STYLE,
  POSTGRES_BODY_CELL_BORDER_CLASS,
  POSTGRES_HEADER_CELL_BORDER_CLASS,
  POSTGRES_STICKY_ACTIONS_HEADER_CLASS,
  POSTGRES_STICKY_THEAD_CLASS,
  postgresStickyActionsCellClass,
} from './postgres-spreadsheet-chrome'

const POSTGRES_ENUMS_GRID_MIN_WIDTH_PX =
  200 + 280 + 120 + 180 + POSTGRES_ACTIONS_COL_PX

type PostgresSchemaEnumsPanelProps = {
  databaseId: string
  schema: string
  search?: string
  createDialogOpen?: boolean
  onCreateDialogOpenChange?: (open: boolean) => void
}

export function PostgresSchemaEnumsPanel({
  databaseId,
  schema,
  search = '',
  createDialogOpen: createDialogOpenProp,
  onCreateDialogOpenChange,
}: PostgresSchemaEnumsPanelProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const { canWrite } = useDatabaseTableOperationsAccess()

  const { enums, isLoading, refetch } = usePostgresSchemaEnums(
    projectId,
    databaseId,
    schema,
  )
  const executeSql = useExecutePostgresSql(projectId, databaseId)

  const [internalDialogOpen, setInternalDialogOpen] = useState(false)
  const dialogOpen = (createDialogOpenProp ?? false) || internalDialogOpen
  const [selectedEnum, setSelectedEnum] = useState<PostgresSchemaEnumRow | null>(
    null,
  )
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [enumToDelete, setEnumToDelete] = useState<string | null>(null)

  useEffect(() => {
    if (createDialogOpenProp) {
      setSelectedEnum(null)
    }
  }, [createDialogOpenProp])

  const closeDialog = () => {
    setInternalDialogOpen(false)
    onCreateDialogOpenChange?.(false)
    setSelectedEnum(null)
  }

  const handleEdit = (enumRow: PostgresSchemaEnumRow) => {
    openDialogAfterOverlayCloses(() => {
      setSelectedEnum(enumRow)
      setInternalDialogOpen(true)
    })
  }

  const openDeleteDialog = (enumName: string) => {
    openDialogAfterOverlayCloses(() => {
      setEnumToDelete(enumName)
      setDeleteDialogOpen(true)
    })
  }

  const handleDelete = async () => {
    if (!enumToDelete) return
    const name = enumToDelete
    closeDialogBeforeOverlayUnmount(() => {
      setDeleteDialogOpen(false)
      setEnumToDelete(null)
    })
    try {
      await executeSql.mutateAsync(buildPostgresDropEnumSql(schema, name))
      toast.success(t('Enum deleted'))
      await refetch()
    } catch (error) {
      toast.error(getErrorMessage(error) ?? t('Failed to delete enum'))
    }
  }

  const filteredEnums = useMemo(() => {
    return enums.filter((enumRow) =>
      matchesPostgresLocalSearch(
        search,
        enumRow.enum_name,
        enumRow.values.join(' '),
        enumRow.enum_comment,
      ),
    )
  }, [enums, search])

  const hasSearch = search.trim().length > 0

  if (isLoading && enums.length === 0) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-[13px] text-muted-foreground">
          {t('Loading enums…')}
        </div>
      </div>
    )
  }

  if (hasSearch && filteredEnums.length === 0) {
    return (
      <div className="flex min-h-0 flex-1 w-full items-center justify-center px-4 py-8">
        <div className="w-full max-w-sm">
          <EmptyState
            variant="centered"
            icon={ListOrdered}
            iconSize="md"
            title={t('No enums match your search')}
            description={t('Try adjusting or clearing your search.')}
            hasFilters
            className="w-full"
          />
        </div>
      </div>
    )
  }

  if (enums.length === 0) {
    return (
      <>
        <div className="flex min-h-0 flex-1 w-full items-center justify-center px-4 py-8">
          <div className="w-full max-w-sm">
            <EmptyState
              variant="centered"
              icon={ListOrdered}
              iconSize="md"
              title={t('No enums')}
              description={
                canWrite
                  ? t(
                      'Use Create enum in the header to define your first enum type for this schema.',
                    )
                  : t('This schema has no enum types yet.')
              }
              isEmpty
              className="w-full"
            />
          </div>
        </div>
        <PostgresSchemaEnumDrawer
          open={dialogOpen}
          onOpenChange={(open) => {
            if (!open) closeDialog()
          }}
          projectId={projectId}
          databaseId={databaseId}
          schema={schema}
          enumRow={selectedEnum}
          onSuccess={() => void refetch()}
        />
      </>
    )
  }

  return (
    <>
      <div className="relative flex h-full flex-col">
        <div className="flex-1 overflow-auto overscroll-contain">
          <div
            className={SPREADSHEET_SCROLL_LAYER_CLASS}
            style={{ minWidth: POSTGRES_ENUMS_GRID_MIN_WIDTH_PX }}
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
                    {t('Name')}
                  </span>
                </th>
                <th
                  className={cn(
                    'min-w-[280px] px-3 py-2 text-start',
                    POSTGRES_HEADER_CELL_BORDER_CLASS,
                  )}
                >
                  <span className="text-[12px] font-medium text-foreground">
                    {t('Values')}
                  </span>
                </th>
                <th
                  className={cn(
                    'min-w-[120px] px-3 py-2 text-start',
                    POSTGRES_HEADER_CELL_BORDER_CLASS,
                  )}
                >
                  <span className="text-[12px] font-medium text-foreground">
                    {t('In use')}
                  </span>
                </th>
                <th
                  className={cn(
                    'min-w-[180px] px-3 py-2 text-start',
                    POSTGRES_HEADER_CELL_BORDER_CLASS,
                  )}
                >
                  <span className="text-[12px] font-medium text-foreground">
                    {t('Description')}
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
              {filteredEnums.map((enumRow) => {
                const usedInSchema = isPostgresEnumUsedInSchema(enumRow)
                const comment = enumRow.enum_comment?.trim() ?? ''

                return (
                  <PostgresEnumContextMenu
                    key={enumRow.enum_name}
                    enumRow={enumRow}
                    canWrite={canWrite}
                    onUpdate={handleEdit}
                    onDelete={openDeleteDialog}
                  >
                    <tr className="group transition-colors hover:bg-muted/50">
                      <td
                        className={cn('px-3 py-2', POSTGRES_BODY_CELL_BORDER_CLASS)}
                      >
                        <div className="flex items-center gap-2">
                          <ListOrdered className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                          <code className="font-mono text-[12px] text-foreground">
                            {enumRow.enum_name}
                          </code>
                        </div>
                      </td>
                      <td
                        className={cn('px-3 py-2', POSTGRES_BODY_CELL_BORDER_CLASS)}
                      >
                        <div className="flex flex-wrap gap-1.5">
                          {enumRow.values.map((value, valueIndex) => (
                            <Badge
                              key={`${enumRow.enum_name}-${valueIndex}-${value}`}
                              variant="outline"
                              className={cn(
                                'text-[11px] font-medium border',
                                getPostgresEnumValueBadgeClass(),
                              )}
                            >
                              {value}
                            </Badge>
                          ))}
                        </div>
                      </td>
                      <td
                        className={cn('px-3 py-2', POSTGRES_BODY_CELL_BORDER_CLASS)}
                      >
                        {usedInSchema ? (
                          <Badge variant="info" className="text-[10px] shrink-0">
                            {t('In use')}
                          </Badge>
                        ) : (
                          <span className="text-[12px] text-muted-foreground">
                            {t('Not used')}
                          </span>
                        )}
                      </td>
                      <td
                        className={cn('px-3 py-2', POSTGRES_BODY_CELL_BORDER_CLASS)}
                      >
                        <span className="text-[12px] text-muted-foreground">
                          {comment || t('No description')}
                        </span>
                      </td>
                      <td aria-hidden className={SPREADSHEET_FILLER_CELL_CLASS} />
                      <td
                        className={postgresStickyActionsCellClass()}
                        style={POSTGRES_ACTIONS_COL_STYLE}
                      >
                        <div
                          className="flex h-full items-center justify-center py-1.5"
                          style={POSTGRES_ACTIONS_COL_STYLE}
                        >
                        {canWrite ? (
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <RowActionsMenuTrigger />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                              <DropdownMenuItem onSelect={() => handleEdit(enumRow)}>
                                <MenuItemContent icon={Pencil}>
                                  {t('Update')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                              <DropdownMenuItem
                                onSelect={() => openDeleteDialog(enumRow.enum_name)}
                              >
                                <MenuItemContent icon={Trash2}>
                                  {t('Delete')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                            </DropdownMenuContent>
                          </DropdownMenu>
                        ) : null}
                        </div>
                      </td>
                    </tr>
                  </PostgresEnumContextMenu>
                )
              })}
            </tbody>
          </table>
          </div>
        </div>
      </div>

      <PostgresSchemaEnumDrawer
        open={dialogOpen}
        onOpenChange={(open) => {
          if (!open) closeDialog()
        }}
        projectId={projectId}
        databaseId={databaseId}
        schema={schema}
        enumRow={selectedEnum}
        onSuccess={() => void refetch()}
      />

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>{t('Delete enum')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Delete')} &quot;{enumToDelete}&quot;?{' '}
              {t('This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => {
                setDeleteDialogOpen(false)
                setEnumToDelete(null)
              }}
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
