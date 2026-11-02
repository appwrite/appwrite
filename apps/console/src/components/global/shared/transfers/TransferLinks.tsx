import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link2, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { Query, type Models } from '@appwrite.io/console'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { buildConsoleUrl, copyToClipboard } from '@/lib/utils/context-menu'
import { useT } from '@/lib/i18n/translate'
import { buildTransferPath } from '@/lib/transfers'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Pagination } from '@/components/global/shared/Pagination'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { Badge } from '@/components/ui/badge'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'

const DEFAULT_PAGE_SIZE = 10

const HEAD_CLASS_NAME =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

/** The claim page link for a transfer, which its sender shares with the recipient. */
export function buildTransferUrl(transfer: Models.Transfer): string {
  return buildConsoleUrl(
    buildTransferPath(
      transfer.resourceType === 'domains' ? 'domains' : 'projects',
      transfer.$id,
      transfer.secret,
    ),
  )
}

/**
 * Pages through a resource's pending transfer links, newest first. `list`
 * fetches one page with the given queries.
 */
export function useTransferLinks(
  queryKey: readonly unknown[],
  list: (queries: string[]) => Promise<Models.TransferList>,
) {
  const t = useT()
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)

  const listPage = (page: number) => ({
    queryKey: [...queryKey, page, pageSize],
    queryFn: () =>
      list([
        Query.orderDesc('$createdAt'),
        Query.limit(pageSize),
        Query.offset((page - 1) * pageSize),
      ]),
  })

  // Fetch the requested page, but keep showing the current one until it arrives
  const requested = useQuery(listPage(requestedPage))
  const displayed = useQuery(listPage(displayedPage))

  useEffect(() => {
    if (
      !requested.isFetching &&
      requestedPage !== displayedPage &&
      requested.data
    ) {
      setDisplayedPage(requestedPage)
    }
  }, [displayedPage, requested.data, requested.isFetching, requestedPage])

  // Go back to the shown page, so the next click requests the failed page again
  useEffect(() => {
    if (
      requested.isError &&
      !requested.isFetching &&
      requestedPage !== displayedPage
    ) {
      toast.error(
        getErrorMessage(requested.error, t('Failed to load transfer links')),
      )
      setRequestedPage(displayedPage)
    }
  }, [
    displayedPage,
    requested.error,
    requested.isError,
    requested.isFetching,
    requestedPage,
    t,
  ])

  // Deleting the last link on the last page leaves that page empty
  const total = displayed.data?.total ?? 0
  const lastPage = Math.max(1, Math.ceil(total / pageSize))
  useEffect(() => {
    if (displayed.data && displayedPage > lastPage) {
      setRequestedPage(lastPage)
      setDisplayedPage(lastPage)
    }
  }, [displayed.data, displayedPage, lastPage])

  return {
    displayed,
    transfers: displayed.data?.transfers ?? [],
    total,
    page: displayedPage,
    pageSize,
    setPage: setRequestedPage,
    setPageSize: (size: number) => {
      setPageSize(size)
      setRequestedPage(1)
      setDisplayedPage(1)
    },
  }
}

interface TransferLinksProps {
  links: ReturnType<typeof useTransferLinks>
  canDelete: boolean
  onDelete: (transfer: Models.Transfer) => void
}

/** The pending links section of a transfer card, shown once there is a link or the list failed. */
export function TransferLinks({
  links,
  canDelete,
  onDelete,
}: TransferLinksProps) {
  const t = useT()
  const { displayed, transfers, total } = links

  if (!displayed.isError && transfers.length === 0) return null

  return (
    <>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        <div>
          <h4 className="text-[13px] font-medium text-foreground">
            {t('Pending transfer links')}
          </h4>
          <p className="text-[12px] text-muted-foreground mt-1">
            {t(
              'Links that have not been claimed yet. Expired links can no longer be claimed. Delete a link to stop it from working sooner.',
            )}
          </p>
        </div>
        {displayed.isError ? (
          <p className="text-[13px] text-muted-foreground">
            {getErrorMessage(
              displayed.error,
              t('Failed to load transfer links'),
            )}
          </p>
        ) : (
          <>
            <div className="rounded-lg border border-border bg-card">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className={HEAD_CLASS_NAME}>
                      {t('Recipient')}
                    </TableHead>
                    <TableHead className={HEAD_CLASS_NAME}>
                      {t('Created')}
                    </TableHead>
                    <TableHead className={HEAD_CLASS_NAME}>
                      {t('Expires')}
                    </TableHead>
                    <TableHead className={`${HEAD_CLASS_NAME} w-[64px]`} />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {transfers.map((transfer) => {
                    const isExpired =
                      new Date(transfer.expire).getTime() <= Date.now()
                    return (
                      <TableRow key={transfer.$id}>
                        <TableCell className="px-4 py-3">
                          {transfer.recipientId ? (
                            <div className="flex items-center gap-2">
                              {transfer.recipientName && (
                                <span className="text-[13px] text-foreground">
                                  {transfer.recipientName}
                                </span>
                              )}
                              <CopyableId id={transfer.recipientId} size="xs" />
                            </div>
                          ) : (
                            <span className="text-[13px] text-muted-foreground">
                              {t('Any organization')}
                            </span>
                          )}
                        </TableCell>
                        <TableCell className="px-4 py-3 text-[13px]">
                          <DateTooltip date={transfer.$createdAt} />
                        </TableCell>
                        <TableCell className="px-4 py-3 text-[13px]">
                          <div className="flex items-center gap-2">
                            <DateTooltip date={transfer.expire} />
                            {isExpired && (
                              <Badge
                                variant="error"
                                className="text-[10px] shrink-0"
                              >
                                {t('Expired')}
                              </Badge>
                            )}
                          </div>
                        </TableCell>
                        <TableCell className="px-4 py-3">
                          <div className="flex justify-end">
                            <DropdownMenu>
                              <DropdownMenuTrigger asChild>
                                <RowActionsMenuTrigger
                                  aria-label={t('Transfer link actions')}
                                />
                              </DropdownMenuTrigger>
                              <DropdownMenuContent align="end" className="w-48">
                                <DropdownMenuItem
                                  disabled={isExpired}
                                  onClick={() =>
                                    copyToClipboard(
                                      t('Transfer link'),
                                      buildTransferUrl(transfer),
                                    )
                                  }
                                >
                                  <MenuItemContent icon={Link2}>
                                    {t('Copy link')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                  disabled={!canDelete}
                                  onClick={() => onDelete(transfer)}
                                >
                                  <MenuItemContent icon={Trash2}>
                                    {t('Delete')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                              </DropdownMenuContent>
                            </DropdownMenu>
                          </div>
                        </TableCell>
                      </TableRow>
                    )
                  })}
                </TableBody>
              </Table>
            </div>
            <Pagination
              currentPage={links.page}
              totalItems={total}
              pageSize={links.pageSize}
              pageSizeOptions={[10, 25, 50, 100]}
              onPageChange={links.setPage}
              onPageSizeChange={links.setPageSize}
              itemLabel={t('transfer links')}
              scrollToTopOnPageChange={false}
            />
          </>
        )}
      </div>
    </>
  )
}
