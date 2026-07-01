import { useState, useMemo, useEffect, useRef } from 'react'
import { useParams, useNavigate, useLocation } from '@tanstack/react-router'
import {
  Hash,
  Mail,
  Phone,
  Bell,
  ScrollText,
  Trash2} from 'lucide-react'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import {
  useTopic,
  useTopicSubscribers,
  useProject,
  useOrganizationScopes} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  canShowTopicSettingsTab,
  canWriteTopics} from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { subscriberLogsQueryOptions } from '@/lib/react-query/hooks/messaging'
import { ServiceHeader, type Tab } from '../../../shared/ServiceHeader'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DetailResourceHeaderTitle } from '@/components/global/shared/ResourceTitleSwitcher'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow} from '@/components/ui/table'

import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import type { Models } from '@appwrite.io/console'
import { ID } from '@appwrite.io/console'
import { MessagingTargetsModal } from '../../_components/MessagingTargetsModal'
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle} from '@/components/ui/alert-dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger} from '@/components/ui/dropdown-menu'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle} from '@/components/ui/dialog'
import { MessagingLogsTable } from '../../_components/MessagingLogsTable'

export type TopicSubscribersInitialData = {
  subscribers: Models.Subscriber[]
  total: number
}

export function View({
  initialSubscribers,
  initialTopic}: {
  initialSubscribers?: TopicSubscribersInitialData
  initialTopic?: Models.Topic
} = {}) {
  const { projectId, topicId } = useParams({
    strict: false})
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()

  const [addTargetsOpen, setAddTargetsOpen] = useState(false)
  const [subscriberPendingDelete, setSubscriberPendingDelete] =
    useState<Models.Subscriber | null>(null)
  const [subscriberLogFor, setSubscriberLogFor] =
    useState<Models.Subscriber | null>(null)
  const [subscriberLogRequestedPage, setSubscriberLogRequestedPage] =
    useState(1)
  const [subscriberLogDisplayedPage, setSubscriberLogDisplayedPage] =
    useState(1)

  const { data: topic } = useTopic(projectId, topicId, initialTopic)
  const topicResolved = topic ?? initialTopic

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const showSettingsTab = canShowTopicSettingsTab(access, features)
  const canManageSubscribers = canWriteTopics(access, features)
  const subscribersPermissionTooltip = !canManageSubscribers
    ? "You don't have permission to manage topic subscribers."
    : undefined

  const [searchValue, setSearchValue] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const searchDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    searchDebounceRef.current = setTimeout(() => {
      setDebouncedSearch(searchValue.trim())
    }, 300)
    return () => {
      if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    }
  }, [searchValue])

  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)

  useEffect(() => {
    setRequestedPage(1)
    setDisplayedPage(1)
  }, [debouncedSearch, topicId])

  const pageIndexedRequested = requestedPage - 1
  const pageIndexedDisplayed = displayedPage - 1

  const {
    isLoading: requestedLoading,
    isFetching: requestedFetching} = useTopicSubscribers(
    projectId,
    topicId,
    pageIndexedRequested,
    pageSize,
    debouncedSearch || undefined,
  )

  const {
    subscribers: displayedSubscribersRaw,
    total: displayedTotalRaw,
    isLoading: displayedLoading,
    isFetched: displayedFetched} = useTopicSubscribers(
    projectId,
    topicId,
    pageIndexedDisplayed,
    pageSize,
    debouncedSearch || undefined,
  )

  useEffect(() => {
    if (requestedFetching || requestedLoading) return
    if (requestedPage !== displayedPage) {
      setDisplayedPage(requestedPage)
    }
  }, [
    requestedFetching,
    requestedLoading,
    requestedPage,
    displayedPage,
  ])

  const subscribers = useMemo(() => {
    if (displayedFetched || displayedSubscribersRaw.length > 0) {
      return displayedSubscribersRaw
    }
    if (
      displayedPage === 1 &&
      !debouncedSearch &&
      initialSubscribers?.subscribers?.length
    ) {
      return initialSubscribers.subscribers
    }
    return []
  }, [
    displayedFetched,
    displayedSubscribersRaw,
    displayedPage,
    debouncedSearch,
    initialSubscribers,
  ])

  const subscribersTotal = useMemo(() => {
    if (displayedFetched || displayedTotalRaw > 0) {
      return displayedTotalRaw
    }
    if (
      displayedPage === 1 &&
      !debouncedSearch &&
      initialSubscribers != null
    ) {
      return initialSubscribers.total
    }
    return displayedTotalRaw
  }, [
    displayedFetched,
    displayedTotalRaw,
    displayedPage,
    debouncedSearch,
    initialSubscribers,
  ])

  const existingSubscriberTargetIds = useMemo(
    () => new Set(subscribers.map((s) => s.targetId)),
    [subscribers],
  )

  const addSubscribersMutation = useMutation({
    mutationFn: async (targetIds: string[]) => {
      if (!projectId || !topicId) {
        throw new Error('Project ID and Topic ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      await Promise.all(
        targetIds.map((targetId) =>
          projectSdk.messaging.createSubscriber({
            topicId,
            subscriberId: ID.unique(),
            targetId}),
        ),
      )
    },
    onSuccess: async (_, targetIds) => {
      await queryClient.refetchQueries({
        queryKey: ['subscribers', 'project', projectId, 'topic', topicId]})
      toast.success(
        `${targetIds.length} subscriber${targetIds.length !== 1 ? 's' : ''} added`,
      )
      setAddTargetsOpen(false)
    },
    onError: (e: Error) => {
      toast.error(getErrorMessage(e) || 'Failed to add subscribers')
    }})

  const deleteSubscriberMutation = useMutation({
    mutationFn: async (subscriber: Models.Subscriber) => {
      if (!projectId || !topicId) {
        throw new Error('Project ID and Topic ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      await projectSdk.messaging.deleteSubscriber({
        topicId,
        subscriberId: subscriber.$id})
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['subscribers', 'project', projectId, 'topic', topicId]})
      toast.success('Subscriber removed')
      setSubscriberPendingDelete(null)
    },
    onError: (e: Error) => {
      toast.error(getErrorMessage(e) || 'Failed to remove subscriber')
    }})

  const subscriberLogPageReq = subscriberLogRequestedPage - 1
  const subscriberLogPageDisp = subscriberLogDisplayedPage - 1

  const {
    isFetching: subscriberLogReqFetching,
    isLoading: subscriberLogReqLoading} = useQuery({
    ...subscriberLogsQueryOptions(
      projectId,
      subscriberLogFor?.$id ?? null,
      subscriberLogPageReq,
      DEFAULT_PAGE_SIZE,
    ),
    enabled: !!projectId && !!subscriberLogFor})

  const { data: subscriberLogDataDisplayed } = useQuery({
    ...subscriberLogsQueryOptions(
      projectId,
      subscriberLogFor?.$id ?? null,
      subscriberLogPageDisp,
      DEFAULT_PAGE_SIZE,
    ),
    enabled: !!projectId && !!subscriberLogFor})

  useEffect(() => {
    if (!subscriberLogFor) {
      setSubscriberLogRequestedPage(1)
      setSubscriberLogDisplayedPage(1)
    }
  }, [subscriberLogFor])

  useEffect(() => {
    if (!subscriberLogFor) return
    if (subscriberLogReqFetching || subscriberLogReqLoading) return
    if (subscriberLogRequestedPage !== subscriberLogDisplayedPage) {
      setSubscriberLogDisplayedPage(subscriberLogRequestedPage)
    }
  }, [
    subscriberLogFor,
    subscriberLogReqFetching,
    subscriberLogReqLoading,
    subscriberLogRequestedPage,
    subscriberLogDisplayedPage,
  ])

  const handleBack = () => {
    navigate({
      to: '/projects/$projectId/messaging/topics',
      params: { projectId: projectId! }})
  }

  const activeTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const topicIndex = pathParts.findIndex(
      (part, idx) => part === 'topics' && pathParts[idx + 1] === topicId,
    )

    if (topicIndex >= 0 && pathParts[topicIndex + 2]) {
      const tabFromPath = pathParts[topicIndex + 2]
      if (tabFromPath === 'settings') {
        return 'settings'
      }
    }

    return 'subscribers'
  }, [location.pathname, topicId])

  const tabs: Tab[] = useMemo(
    () => [
      {
        id: 'subscribers',
        label: 'Subscribers',
        to: '/projects/$projectId/messaging/topics/$topicId',
        params: {
          projectId: projectId as string,
          topicId: topicId as string}},
      ...(showSettingsTab
        ? [
            {
              id: 'settings' as const,
              label: 'Settings',
              to: '/projects/$projectId/messaging/topics/$topicId/settings',
              params: {
                projectId: projectId as string,
                topicId: topicId as string}},
          ]
        : []),
    ],
    [projectId, topicId, showSettingsTab],
  )

  useEffect(() => {
    if (showSettingsTab || !projectId || !topicId) return
    if (activeTab === 'settings') {
      navigate({
        to: '/projects/$projectId/messaging/topics/$topicId',
        params: { projectId, topicId },
        replace: true})
    }
  }, [showSettingsTab, activeTab, projectId, topicId, navigate])

  const getTypeIcon = (type: string) => {
    if (type === 'email') return Mail
    if (type === 'sms') return Phone
    if (type === 'push') return Bell
    return Hash
  }

  if (!topicResolved) {
    return (
      <div className="flex items-center justify-center py-16">
        <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
          <p className="text-[13px] text-muted-foreground">Topic not found</p>
        </div>
      </div>
    )
  }

  const showSubscribersFullLoading =
    activeTab === 'subscribers' &&
    displayedLoading &&
    subscribers.length === 0 &&
    !(initialSubscribers && displayedPage === 1 && !debouncedSearch)

  const handlePageChange = (page: number) => {
    setRequestedPage(page)
  }

  const handlePageSizeChange = (size: number) => {
    setPageSize(size)
    setRequestedPage(1)
    setDisplayedPage(1)
  }

  const subscriberLogRows = subscriberLogDataDisplayed?.logs ?? []
  const subscriberLogTotal = subscriberLogDataDisplayed?.total ?? 0
  const subscriberLogFullLoading =
    !!subscriberLogFor &&
    subscriberLogRows.length === 0 &&
    (subscriberLogReqLoading || subscriberLogReqFetching)

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={
          <DetailResourceHeaderTitle
            kind="topic"
            label={topicResolved.name}
            resourceId={topicResolved.$id}
            projectId={projectId}
            back={{
              onClick: handleBack,
              'aria-label': 'Back to topics'}}
          />
        }
        tabs={tabs}
        activeTab={activeTab}
        searchPlaceholder={
          activeTab === 'subscribers' ? 'Search subscribers...' : undefined
        }
        searchValue={activeTab === 'subscribers' ? searchValue : undefined}
        onSearchChange={
          activeTab === 'subscribers'
            ? (value) => {
                setSearchValue(value)
                setRequestedPage(1)
                setDisplayedPage(1)
              }
            : undefined
        }
        createLabel={activeTab === 'subscribers' ? 'Add subscriber' : undefined}
        onCreate={
          activeTab === 'subscribers'
            ? () => setAddTargetsOpen(true)
            : undefined
        }
        createDisabled={
          activeTab === 'subscribers' ? !canManageSubscribers : undefined
        }
        createDisabledTooltip={
          activeTab === 'subscribers' ? subscribersPermissionTooltip : undefined
        }
        fullWidthBorder
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        {activeTab === 'subscribers' ? (
          <>
            {showSubscribersFullLoading ? (
              <div className="rounded-lg border border-border bg-card py-12 text-center">
                <p className="text-[13px] text-muted-foreground">
                  Loading subscribers...
                </p>
              </div>
            ) : subscribers.length > 0 ? (
              <>
                <div className="rounded-lg border border-border bg-card overflow-hidden">
                  <Table>
                    <TableHeader>
                      <TableRow className="hover:bg-transparent border-b border-border">
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                          Subscriber ID
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                          Name
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                          Target ID
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                          Target
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                          Type
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                          Created
                        </TableHead>
                        <TableHead className="px-4 py-3 w-[52px]" />
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {subscribers.map((subscriber) => {
                        const target = subscriber.target
                        const TypeIcon = getTypeIcon(subscriber.providerType)
                        const nameLabel = (() => {
                          if (!target) return '-'
                          const fromName = target.name?.trim()
                          if (fromName) return fromName
                          const fromId = target.identifier?.trim()
                          if (fromId) return fromId
                          if (target.userId) return target.userId
                          return '-'
                        })()

                        return (
                          <TableRow key={subscriber.$id}>
                            <TableCell className="px-4 py-3">
                              <CopyableId id={subscriber.$id} size="xs" />
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <span className="text-[13px] text-foreground">
                                {nameLabel}
                              </span>
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <CopyableId id={subscriber.targetId} size="xs" />
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <span className="text-[13px] text-muted-foreground">
                                {target?.identifier ||
                                  target?.name ||
                                  subscriber.targetId}
                              </span>
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <div className="flex items-center gap-2">
                                <TypeIcon className="h-4 w-4 text-muted-foreground" />
                                <span className="text-[13px] text-muted-foreground capitalize">
                                  {subscriber.providerType}
                                </span>
                              </div>
                            </TableCell>
                            <TableCell className="px-4 py-3 text-end">
                              {subscriber.$createdAt ? (
                                <DateTooltip
                                  date={subscriber.$createdAt}
                                  className="text-[12px] text-muted-foreground font-mono"
                                />
                              ) : (
                                <span className="text-[12px] text-muted-foreground/50 italic">
                                  N/A
                                </span>
                              )}
                            </TableCell>
                            <TableCell className="px-4 py-3 text-end">
                              <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                  <RowActionsMenuTrigger aria-label="Subscriber actions" />
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                  <DropdownMenuItem
                                    onClick={() =>
                                      setSubscriberLogFor(subscriber)
                                    }
                                  >
                                    <MenuItemContent icon={ScrollText}>
                                      Logs
                                    </MenuItemContent>
                                  </DropdownMenuItem>
                                  <DropdownMenuItem
                                    disabled={!canManageSubscribers}
                                    title={subscribersPermissionTooltip}
                                    onClick={() =>
                                      setSubscriberPendingDelete(subscriber)
                                    }
                                  >
                                    <MenuItemContent icon={Trash2}>
                                      Remove
                                    </MenuItemContent>
                                  </DropdownMenuItem>
                                </DropdownMenuContent>
                              </DropdownMenu>
                            </TableCell>
                          </TableRow>
                        )
                      })}
                    </TableBody>
                  </Table>
                </div>
                <Pagination
                  className="py-2"
                  currentPage={displayedPage}
                  totalItems={subscribersTotal}
                  pageSize={pageSize}
                  pageSizeOptions={[10, 25, 50, 100]}
                  onPageChange={handlePageChange}
                  onPageSizeChange={handlePageSizeChange}
                  itemLabel="subscribers"
                />
              </>
            ) : (
              <EmptyState
                icon={Hash}
                title="No subscribers yet"
                description="Add subscribers to this topic to start sending messages"
                isEmpty={!debouncedSearch}
                hasFilters={!!debouncedSearch}
                variant="card"
              />
            )}
          </>
        ) : null}
      </div>

      <MessagingTargetsModal
        open={addTargetsOpen}
        onOpenChange={setAddTargetsOpen}
        title="Add subscribers"
        description="Select user targets to subscribe to this topic. Targets already subscribed are skipped."
        projectId={projectId}
        initialSelectedById={{} as Record<string, Models.Target | undefined>}
        onConfirm={(selectedById) => {
          const newTargetIds = Object.keys(selectedById).filter(
            (id) => !existingSubscriberTargetIds.has(id),
          )
          if (newTargetIds.length === 0) {
            toast.info('No new targets selected (or all are already subscribed)')
            return
          }
          addSubscribersMutation.mutate(newTargetIds)
        }}
      />

      <Dialog
        open={subscriberLogFor != null}
        onOpenChange={(open) => {
          if (!open) setSubscriberLogFor(null)
        }}
      >
        <DialogContent className="sm:max-w-2xl p-0 max-h-[90dvh] flex flex-col">
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>Subscriber logs</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Audit log events for subscriber{' '}
              {subscriberLogFor ? (
                <CopyableId id={subscriberLogFor.$id} size="xs" />
              ) : null}
              .
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-0 flex-1 min-h-0 overflow-y-auto space-y-4">
            {subscriberLogFullLoading ? (
              <p className="text-[13px] text-muted-foreground py-6 text-center">
                Loading logs…
              </p>
            ) : (
              <>
                <MessagingLogsTable
                  logs={subscriberLogRows}
                  emptyLabel="No log entries for this subscriber."
                />
                <Pagination
                  className="py-1"
                  currentPage={subscriberLogDisplayedPage}
                  totalItems={subscriberLogTotal}
                  pageSize={DEFAULT_PAGE_SIZE}
                  pageSizeOptions={[10, 25, 50, 100]}
                  onPageChange={setSubscriberLogRequestedPage}
                  onPageSizeChange={() => {}}
                  showPageSizeSelector={false}
                  itemLabel="entries"
                />
              </>
            )}
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setSubscriberLogFor(null)}
            >
              Close
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <AlertDialog
        open={subscriberPendingDelete != null}
        onOpenChange={(open) => {
          if (!open) setSubscriberPendingDelete(null)
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Remove subscriber?</AlertDialogTitle>
            <AlertDialogDescription className="text-[13px]">
              This subscriber will be removed from the topic. You can add them
              again later.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deleteSubscriberMutation.isPending}>
              Cancel
            </AlertDialogCancel>
            <Button
              disabled={
                !canManageSubscribers || deleteSubscriberMutation.isPending
              }
              onClick={() => {
                if (subscriberPendingDelete) {
                  deleteSubscriberMutation.mutate(subscriberPendingDelete)
                }
              }}
            >
              Remove
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
