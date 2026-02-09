import { useState, useMemo, useEffect } from 'react'
import { useNavigate } from '@tanstack/react-router'
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
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { CreateWebhookDialog } from './webhooks/CreateWebhook'

interface WebhooksProps {
  projectId: string
  searchValue?: string
}

export function Webhooks({
  projectId,
  searchValue: searchValueProp = '',
}: WebhooksProps) {
  const navigate = useNavigate()
  const [currentPage, setCurrentPage] = useState(1)
  const [pageSize, setPageSize] = useState(25)
  const [createWebhookOpen, setCreateWebhookOpen] = useState(false)

  const { webhooks, isLoading } = useProjectWebhooks(projectId)

  // Listen for create event from ServiceHeader
  useEffect(() => {
    const handleCreate = () => {
      setCreateWebhookOpen(true)
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
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    Created
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    Updated
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {paginatedWebhooks.map((webhook) => (
                  <TableRow
                    key={webhook.$id}
                    className="cursor-pointer"
                    onClick={() =>
                      navigate({
                        to: '/projects/$projectId/settings/webhooks/$webhookId',
                        params: { projectId, webhookId: webhook.$id },
                      })
                    }
                  >
                    <TableCell className="px-4 py-3">
                      <code className="text-[13px] font-mono text-muted-foreground">
                        {webhook.$id.slice(0, 8)}...
                      </code>
                    </TableCell>
                    <TableCell className="px-4 py-3 font-medium">
                      {webhook.name}
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <Badge variant="secondary" className="text-[12px]">
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
                        <Badge variant="default" className="text-[12px]">
                          Enabled
                        </Badge>
                      ) : (
                        <Badge variant="secondary" className="text-[12px]">
                          Disabled
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <DateTooltip date={webhook.$createdAt} />
                    </TableCell>
                    <TableCell className="px-4 py-3">
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

      {/* Create Webhook Dialog */}
      <CreateWebhookDialog
        open={createWebhookOpen}
        onOpenChange={setCreateWebhookOpen}
        projectId={projectId}
        onCreateSuccess={(webhookId) => {
          navigate({
            to: '/projects/$projectId/settings/webhooks/$webhookId',
            params: { projectId, webhookId },
          })
        }}
      />
    </div>
  )
}
