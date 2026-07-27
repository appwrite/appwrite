import { useMemo } from 'react'
import { useParams } from '@tanstack/react-router'
import { getColumnIcon } from '@/lib/utils/column-icons'
import { useProjectCollectionAttributes } from '@/lib/react-query/hooks'
import { COLUMNS_INDEXES_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import type { Models } from '@appwrite.io/console'
import { Columns3 } from 'lucide-react'
import { getLocalizedDatabaseConsoleLabels } from '@/lib/database-console-labels'
import {
  isDatabaseRouteKind,
  type DatabaseRouteKind,
} from '@/lib/database-routes'

type CollectionAttributesSpreadsheetProps = {
  table: Models.Collection | { $id: string; name?: string }
}

function getAttributeKey(attr: Record<string, unknown>): string {
  return String(attr.key ?? attr.name ?? attr.$id ?? '')
}

function getAttributeType(attr: Record<string, unknown>): string {
  return String(attr.type ?? 'string')
}

export function CollectionAttributesSpreadsheet({
  table,
}: CollectionAttributesSpreadsheetProps) {
  const t = useT()
  const params = useParams({ strict: false })
  const projectId = params.projectId as string
  const databaseId = params.databaseId as string
  const collectionId = table.$id
  const dbKind: DatabaseRouteKind = isDatabaseRouteKind(
    String(params.dbKind ?? ''),
  )
    ? (params.dbKind as DatabaseRouteKind)
    : 'documentsdb'
  const dbLabels = getLocalizedDatabaseConsoleLabels(t, dbKind)

  const { columns, total, isLoading } = useProjectCollectionAttributes(
    projectId,
    databaseId,
    dbKind,
    collectionId,
    undefined,
    0,
    COLUMNS_INDEXES_DEFAULT_PAGE_SIZE,
  )

  const attributes = useMemo(
    () => (columns ?? []) as Record<string, unknown>[],
    [columns],
  )

  if (isLoading && attributes.length === 0) {
    return (
      <div className="flex flex-1 items-center justify-center p-8 text-[13px] text-muted-foreground">
        {t('Loading attributes…')}
      </div>
    )
  }

  if (attributes.length === 0) {
    return (
      <div className="flex h-full flex-col">
        <div className="flex flex-1 items-center justify-center px-6 py-12">
          <EmptyState
            icon={Columns3}
            title={dbLabels.emptyGridNoSchemaTitle}
            description={t(
              'Define attributes when creating a collection, or store flexible fields on documents without a fixed schema.',
            )}
            isEmpty
            variant="centered"
            iconSize="md"
          />
        </div>
      </div>
    )
  }

  return (
    <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6">
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                {t('Key')}
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                {t('Type')}
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                {t('Required')}
              </TableHead>
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                {t('Status')}
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {attributes.map((attr) => {
              const key = getAttributeKey(attr)
              const type = getAttributeType(attr)
              const Icon = getColumnIcon(type)
              const required = Boolean(attr.required)
              const status = String(attr.status ?? 'available')
              return (
                <TableRow key={key || String(attr.$id ?? Math.random())}>
                  <TableCell className="px-4 py-3">
                    <span className="inline-flex items-center gap-2 text-[13px] font-medium">
                      <Icon className="h-4 w-4 text-muted-foreground shrink-0" />
                      {key}
                    </span>
                  </TableCell>
                  <TableCell className="px-4 py-3 text-[13px] text-muted-foreground">
                    {type}
                  </TableCell>
                  <TableCell className="px-4 py-3 text-[13px] text-muted-foreground">
                    {required ? 'Yes' : 'No'}
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <Badge
                      variant={
                        status === 'available'
                          ? 'success'
                          : status === 'processing'
                            ? 'warning'
                            : 'error'
                      }
                      className="text-[10px] shrink-0"
                    >
                      {localizeResourceStatusLabel(status, t)}
                    </Badge>
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      </div>
      {total > COLUMNS_INDEXES_DEFAULT_PAGE_SIZE ? (
        <div className="mt-4">
          <Pagination
            currentPage={1}
            totalItems={total}
            pageSize={COLUMNS_INDEXES_DEFAULT_PAGE_SIZE}
            onPageChange={() => undefined}
            itemLabel="attributes"
          />
        </div>
      ) : null}
    </div>
  )
}
