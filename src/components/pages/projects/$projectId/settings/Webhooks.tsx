import { useState, useMemo, useEffect } from 'react'
import { useProjectWebhooks } from '@/lib/react-query/hooks'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { Loader2, Webhook as WebhookIcon } from 'lucide-react'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { WebhookDrawer } from './webhooks/WebhookDrawer'
import type { Models } from '@appwrite.io/console'

interface WebhooksProps {
  projectId: string
  searchValue?: string
}

export function Webhooks({
  projectId,
  searchValue: searchValueProp = '',
}: WebhooksProps) {
  const [currentPage, setCurrentPage] = useState(1)
  const [pageSize, setPageSize] = useState(25)
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [selectedWebhook, setSelectedWebhook] =
    useState<Models.Webhook | null>(null)

  const { webhooks, isLoading } = useProjectWebhooks(projectId)

  // Listen for create event from ServiceHeader
  useEffect(() => {
    const handleCreate = () => {
      setSelectedWebhook(null)
      setDrawerOpen(true)
    }
    window.addEventListener('settings-create-webhook', handleCreate)
    return () => {
      window.removeEventListener('settings-create-webhook', handleCreate)
    }
  }, [])

  // Filter webhooks by search
  const filteredWebhooks = useMemo(() => {
    if (!searchValueProp.trim()) return webhooks
    const search = searchValueProp.toLowerCase()
    return webhooks.filter(
      (webhook) =>
        webhook.name.toLowerCase().includes(search) ||
        webhook.url.toLowerCase().includes(search) ||
        webhook.$id.toLowerCase().includes(search),
    )
  }, [webhooks, searchValueProp])

  // Convert 1-indexed page to 0-indexed for pagination
  const pageIndexed = currentPage - 1
  const paginatedWebhooks = useMemo(() => {
    const start = pageIndexed * pageSize
    const end = start + pageSize
    return filteredWebhooks.slice(start, end)
  }, [filteredWebhooks, pageIndexed, pageSize])

  return (
    <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
      {isLoading ? (
        <div className="flex h-64 items-center justify-center">
          <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
        </div>
      ) : paginatedWebhooks.length === 0 ? (
        <EmptyState
          icon={WebhookIcon}
          title={searchValueProp ? undefined : 'No webhooks yet'}
          description={
            searchValueProp
              ? undefined
              : 'Set up webhooks to receive real-time notifications about events in your project'
          }
          isEmpty={!searchValueProp}
          hasFilters={!!searchValueProp}
          variant="card"
        />
      ) : (
        <>
          <div className="rounded-lg border border-border bg-card">
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    Webhook ID
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    Name
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    Events
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    URL
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    Enabled
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                    Created
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                    Updated
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {paginatedWebhooks.map((webhook) => (
                  <TableRow
                    key={webhook.$id}
                    className="cursor-pointer"
                    onClick={() => {
                      setSelectedWebhook(webhook)
                      setDrawerOpen(true)
                    }}
                  >
                    <TableCell className="px-4 py-3">
                      <CopyableId id={webhook.$id} size="xs" />
                    </TableCell>
                    <TableCell className="px-4 py-3 font-medium">
                      {webhook.name}
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <Badge variant="info" className="text-[10px] shrink-0">
                        {webhook.events?.length || 0} events
                      </Badge>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <span className="truncate text-[13px] text-muted-foreground">
                        {webhook.url}
                      </span>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      {webhook.enabled ? (
                        <Badge variant="success" className="text-[10px] shrink-0">
                          Enabled
                        </Badge>
                      ) : (
                        <Badge variant="inactive" className="text-[10px] shrink-0">
                          Disabled
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell className="px-4 py-3 text-right">
                      <DateTooltip date={webhook.$createdAt} />
                    </TableCell>
                    <TableCell className="px-4 py-3 text-right">
                      <DateTooltip date={webhook.$updatedAt} />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>

          {filteredWebhooks.length > 0 && (
            <Pagination
              currentPage={currentPage}
              totalItems={filteredWebhooks.length}
              pageSize={pageSize}
              pageSizeOptions={[10, 25, 50, 100]}
              onPageChange={setCurrentPage}
              onPageSizeChange={(size) => {
                setPageSize(size)
                setCurrentPage(1)
              }}
              itemLabel="webhooks"
            />
          )}
        </>
      )}

      <WebhookDrawer
        open={drawerOpen}
        onOpenChange={setDrawerOpen}
        projectId={projectId}
        webhook={selectedWebhook}
        onSuccess={() => setSelectedWebhook(null)}
      />
    </div>
  )
}
