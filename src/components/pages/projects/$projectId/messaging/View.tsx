import { useState, useMemo, useEffect, useRef } from 'react'
import { useParams, useLocation, useNavigate } from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import { Mail, Phone, Bell, Loader2, MessageSquare } from 'lucide-react'
import {
  useProjectMessages,
  useProjectTopics,
  useProjectProviders,
  useProject,
  useOrganizationPlan,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  canWriteMessages,
  canWriteTopics,
  canWriteProviders,
} from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'

import { Link } from '@tanstack/react-router'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import { localizeResourceStatusLabel } from '@/lib/i18n/resource-status-labels'
import { sdk } from '@/lib/appwrite/sdk'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import type { Models } from '@appwrite.io/console'
import { MessagingProviderIcon } from '@/components/global/shared/MessagingProviderIcon'
import { MessageContextMenu } from './_components/MessageContextMenu'
import { TopicContextMenu } from './_components/TopicContextMenu'
import { ProviderContextMenu } from './_components/ProviderContextMenu'
import { MessagingCreateControls } from './_components/MessagingCreateControls'

function formatDeliveryErrors(
  deliveryErrors: unknown,
): string[] {
  if (deliveryErrors == null) return []
  if (Array.isArray(deliveryErrors)) {
    return deliveryErrors.map((e) => {
      if (typeof e === 'string') return e
      if (e && typeof e === 'object' && 'message' in e) {
        return String((e as { message?: string }).message ?? '')
      }
      try {
        return JSON.stringify(e)
      } catch {
        return String(e)
      }
    })
  }
  if (typeof deliveryErrors === 'object') {
    try {
      return [JSON.stringify(deliveryErrors, null, 2)]
    } catch {
      return [String(deliveryErrors)]
    }
  }
  return [String(deliveryErrors)]
}

export function View() {
  const t = useT()
  const { projectId } = useParams({
    strict: false,
  })
  const location = useLocation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  // Derive active tab from pathname
  const activeTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const messagingIndex = pathParts.findIndex((part) => part === 'messaging')

    if (messagingIndex >= 0) {
      if (pathParts[messagingIndex + 1]) {
        const tabFromPath = pathParts[messagingIndex + 1]
        if (['topics', 'providers'].includes(tabFromPath)) {
          return tabFromPath
        }
      }
    }

    // Default to messages for index route
    return 'messages'
  }, [location.pathname])

  const [searchValue, setSearchValue] = useState('')
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)

  useEffect(() => {
    setRequestedPage(1)
    setDisplayedPage(1)
  }, [projectId])
  const [selectedItems, setSelectedItems] = useState<Set<string>>(new Set())
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [deliveryErrorLines, setDeliveryErrorLines] = useState<string[] | null>(
    null,
  )
  const pollMessagesRef = useRef<ReturnType<typeof setInterval> | null>(null)

  // Fetch requested page (triggers load when user changes page) - active tab only
  const {
    total: messagesTotal,
    isFetching: messagesFetching,
    refetch: refetchMessages,
  } = useProjectMessages(
    activeTab === 'messages' ? projectId : null,
    requestedPage - 1,
    pageSize,
    activeTab === 'messages' ? searchValue : undefined,
  )
  const {
    total: topicsTotal,
    isFetching: topicsFetching,
  } = useProjectTopics(
    activeTab === 'topics' ? projectId : null,
    requestedPage - 1,
    pageSize,
    activeTab === 'topics' ? searchValue : undefined,
  )
  const {
    total: providersTotal,
    isFetching: providersFetching,
  } = useProjectProviders(
    activeTab === 'providers' ? projectId : null,
    requestedPage - 1,
    pageSize,
    activeTab === 'providers' ? searchValue : undefined,
  )

  // Fetch displayed page (what we show - stays until new page is ready) - active tab only
  const {
    messages,
    total: displayedMessagesTotal,
    refetch: refetchDisplayedMessages,
  } = useProjectMessages(
    activeTab === 'messages' ? projectId : null,
    displayedPage - 1,
    pageSize,
    activeTab === 'messages' ? searchValue : undefined,
  )
  const { topics, total: displayedTopicsTotal } = useProjectTopics(
    activeTab === 'topics' ? projectId : null,
    displayedPage - 1,
    pageSize,
    activeTab === 'topics' ? searchValue : undefined,
  )
  const { providers, total: displayedProvidersTotal } = useProjectProviders(
    activeTab === 'providers' ? projectId : null,
    displayedPage - 1,
    pageSize,
    activeTab === 'providers' ? searchValue : undefined,
  )

  const activeFetching =
    activeTab === 'messages'
      ? messagesFetching
      : activeTab === 'topics'
        ? topicsFetching
        : providersFetching
  // Update displayed page only when requested page data is ready (no flash)
  useEffect(() => {
    if (!activeFetching && requestedPage !== displayedPage) {
      setDisplayedPage(requestedPage)
    }
  }, [activeFetching, requestedPage, displayedPage])

  // Poll message list while any visible message is still processing (matches legacy console behavior)
  useEffect(() => {
    if (activeTab !== 'messages' || !projectId) {
      if (pollMessagesRef.current) {
        clearInterval(pollMessagesRef.current)
        pollMessagesRef.current = null
      }
      return
    }
    const hasProcessing = (messages ?? []).some((m) => m.status === 'processing')
    if (!hasProcessing) {
      if (pollMessagesRef.current) {
        clearInterval(pollMessagesRef.current)
        pollMessagesRef.current = null
      }
      return
    }
    if (pollMessagesRef.current) {
      clearInterval(pollMessagesRef.current)
    }
    pollMessagesRef.current = setInterval(() => {
      void refetchDisplayedMessages()
      void refetchMessages()
    }, 2000)
    return () => {
      if (pollMessagesRef.current) {
        clearInterval(pollMessagesRef.current)
        pollMessagesRef.current = null
      }
    }
  }, [
    activeTab,
    projectId,
    messages,
    refetchDisplayedMessages,
    refetchMessages,
  ])

  // Get current data based on active tab (use displayed data and displayed total for stable range)
  const currentData = useMemo(() => {
    if (activeTab === 'messages') {
      return {
        items: messages,
        total: displayedMessagesTotal ?? messagesTotal,
      }
    }
    if (activeTab === 'topics') {
      return {
        items: topics,
        total: displayedTopicsTotal ?? topicsTotal,
      }
    }
    if (activeTab === 'providers') {
      return {
        items: providers,
        total: displayedProvidersTotal ?? providersTotal,
      }
    }
    return { items: [], total: 0 }
  }, [
    activeTab,
    messages,
    displayedMessagesTotal,
    messagesTotal,
    topics,
    displayedTopicsTotal,
    topicsTotal,
    providers,
    displayedProvidersTotal,
    providersTotal,
  ])

  // Get project to get teamId for organization plan
  const { project } = useProject(projectId)

  // Get organization plan to check limits
  useOrganizationPlan(project?.teamId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)

  const noCreatePermission =
    activeTab === 'messages'
      ? !canWriteMessages(access, features)
      : activeTab === 'topics'
        ? !canWriteTopics(access, features)
        : !canWriteProviders(access, features)
  const createPermissionTooltip = noCreatePermission
    ? activeTab === 'messages'
      ? t("You don't have permission to create messages.") // pragma: allowlist secret
      : activeTab === 'topics'
        ? t("You don't have permission to create topics.")
        : t("You don't have permission to create providers.")
    : undefined

  // Clear selection when navigating or searching
  useEffect(() => {
    setSelectedItems(new Set())
    setDeleteDialogOpen(false)
  }, [location.pathname, projectId, searchValue, activeTab])

  const handleSearchChange = (value: string) => {
    setSearchValue(value)
    setRequestedPage(1)
    setDisplayedPage(1)
    setSelectedItems(new Set())
  }

  // Bulk delete mutation
  const bulkDeleteMutation = useMutation({
    mutationFn: async (itemIds: string[]) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      const projectSdk = sdk.forProject(projectId)

      if (activeTab === 'messages') {
        // Delete messages
        await Promise.all(
          itemIds.map((messageId) =>
            projectSdk.messaging.delete({ messageId }),
          ),
        )
      } else if (activeTab === 'topics') {
        // Delete topics
        await Promise.all(
          itemIds.map((topicId) =>
            projectSdk.messaging.deleteTopic({ topicId }),
          ),
        )
      } else if (activeTab === 'providers') {
        // Delete providers
        await Promise.all(
          itemIds.map((providerId) =>
            projectSdk.messaging.deleteProvider({ providerId }),
          ),
        )
      }
    },
    onSuccess: async () => {
      // Refetch list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: [
          activeTab === 'messages'
            ? 'messages'
            : activeTab === 'topics'
              ? 'topics'
              : 'providers',
          'project',
          projectId,
        ],
      })
      toast.success(
        `${t('Successfully deleted')} ${selectedItems.size} ${selectedItems.size > 1 ? t(activeTab) : t(activeTab.slice(0, -1))}`,
      )
      setSelectedItems(new Set())
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to delete items'))
    },
  })

  const handleBulkDelete = () => {
    if (selectedItems.size === 0) return
    setDeleteDialogOpen(true)
  }

  const confirmBulkDelete = () => {
    if (selectedItems.size === 0) return
    bulkDeleteMutation.mutate(Array.from(selectedItems))
  }

  const toggleItem = (itemId: string) => {
    const newSelected = new Set(selectedItems)
    if (newSelected.has(itemId)) {
      newSelected.delete(itemId)
    } else {
      newSelected.add(itemId)
    }
    setSelectedItems(newSelected)
  }

  const toggleAllItems = () => {
    const items = currentData.items ?? []
    if (selectedItems.size === items.length) {
      setSelectedItems(new Set())
    } else {
      setSelectedItems(
        new Set(
          items
            .map((item: { $id?: string }) => item?.$id)
            .filter(Boolean) as string[],
        ),
      )
    }
  }

  const handlePageChange = (page: number) => {
    setRequestedPage(page)
    setSelectedItems(new Set())
  }

  const handlePageSizeChange = (newPageSize: number) => {
    setPageSize(newPageSize)
    setRequestedPage(1)
    setDisplayedPage(1)
    setSelectedItems(new Set())
  }

  const tabs: Tab[] = useMemo(
    () => [
      {
        id: 'messages',
        label: t('Messages'), // pragma: allowlist secret
        to: '/projects/$projectId/messaging/',
        params: { projectId: projectId as string },
      },
      {
        id: 'topics',
        label: t('Topics'),
        to: '/projects/$projectId/messaging/topics',
        params: { projectId: projectId as string },
      },
      {
        id: 'providers',
        label: t('Providers'),
        to: '/projects/$projectId/messaging/providers',
        params: { projectId: projectId as string },
      },
    ],
    [projectId, t],
  )

  // Get message type icon
  const getMessageTypeIcon = (providerType: string) => {
    if (providerType === 'email') return Mail
    if (providerType === 'sms') return Phone
    if (providerType === 'push') return Bell
    return MessageSquare
  }

  // Get message status badge
  const getMessageStatusBadge = (
    status: string,
    deliveryErrors?: unknown,
  ) => {
    if (status === 'sent') {
      return (
        <Badge variant="success" className="text-[10px] shrink-0">
          {t('Sent')}
        </Badge>
      )
    }
    if (status === 'processing') {
      return (
        <div className="flex items-center gap-2">
          <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" />
          <Badge variant="processing" className="text-[10px] shrink-0">
            {t('Processing')}
          </Badge>
        </div>
      )
    }
    if (status === 'failed') {
      const lines = formatDeliveryErrors(deliveryErrors)
      return (
        <div className="flex items-center gap-2">
          <Badge variant="error" className="text-[10px] shrink-0">
            {t('Failed')}
          </Badge>
          {lines.length > 0 && (
            <Button
              variant="ghost"
              size="sm"
              className="h-6 px-2 text-xs"
              onClick={(e) => {
                e.stopPropagation()
                setDeliveryErrorLines(lines)
              }}
            >
              {t('Details')}
            </Button>
          )}
        </div>
      )
    }
    if (status === 'draft') {
      return (
        <Badge variant="info" className="text-[10px] shrink-0">
          {t('Draft')}
        </Badge>
      )
    }
    if (status === 'scheduled') {
      return (
        <Badge variant="warning" className="text-[10px] shrink-0">
          {t('Scheduled')}
        </Badge>
      )
    }
    return (
      <Badge variant="info" className="text-[10px] shrink-0 capitalize">
        {localizeResourceStatusLabel(status, t)}
      </Badge>
    )
  }

  // Get message content preview
  const getMessageContent = (message: Models.Message) => {
    if (message.providerType === 'push' && message.data?.title) {
      return message.data.title
    }
    if (message.providerType === 'sms' && message.data?.content) {
      return message.data.content
    }
    if (message.providerType === 'email' && message.data?.subject) {
      return message.data.subject
    }
    return t('No content')
  }

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={t('Messaging')}
        tabs={tabs}
        activeTab={activeTab}
        searchPlaceholder={
          activeTab === 'messages'
            ? t('Search messages...') // pragma: allowlist secret
            : activeTab === 'topics'
              ? t('Search topics...')
              : t('Search providers...')
        }
        searchValue={searchValue}
        onSearchChange={handleSearchChange}
        fullWidthBorder
        beforeCreateButtons={
          <MessagingCreateControls
            projectId={projectId}
            activeTab={
              activeTab as 'messages' | 'topics' | 'providers'
            }
            disabled={noCreatePermission}
            disabledTooltip={createPermissionTooltip}
          />
        }
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        {(currentData.items ?? []).length > 0 ? (
            <>
              <div className="rounded-lg border border-border bg-card overflow-hidden">
                <Table>
                  <TableHeader>
                    <TableRow className="hover:bg-transparent border-b border-border">
                      <TableHead className="w-[40px] px-4">
                        <Checkbox
                          checked={
                            (currentData.items ?? []).length > 0 &&
                            selectedItems.size ===
                              (currentData.items ?? []).length
                          }
                          onCheckedChange={toggleAllItems}
                        />
                      </TableHead>
                      {activeTab === 'messages' ? (
                        <>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            {t('Message ID')}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            {t('Message')}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            {t('Type')}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            {t('Status')}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                            {t('Scheduled at')}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                            {t('Delivered at')}
                          </TableHead>
                        </>
                      ) : activeTab === 'topics' ? (
                        <>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            {t('Topic ID')}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            {t('Name')}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                            {t('Subscribers')}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                            {t('Created')}
                          </TableHead>
                        </>
                      ) : (
                        <>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            {t('Provider ID')}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            {t('Provider')}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            {t('Type')}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            {t('Enabled')}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            {t('Name')}
                          </TableHead>
                        </>
                      )}
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {activeTab === 'messages' &&
                      (currentData.items ?? []).map(
                        (message: Models.Message) => {
                          if (!message?.$id) return null
                          const TypeIcon = getMessageTypeIcon(
                            message.providerType,
                          )
                          return (
                            <MessageContextMenu
                              key={message.$id}
                              projectId={projectId!}
                              message={{
                                $id: message.$id,
                                providerType: message.providerType,
                              }}
                            >
                              <TableRow
                                className={cn(
                                  'cursor-pointer transition-colors border-b border-border/50',
                                  selectedItems.has(message.$id)
                                    ? 'bg-muted'
                                    : 'hover:bg-muted/30',
                                )}
                                onClick={(e) => {
                                  const target = e.target as HTMLElement
                                  if (
                                    target.closest('button') ||
                                    target.closest('[role="checkbox"]') ||
                                    target.closest('a')
                                  ) {
                                    return
                                  }
                                  navigate({
                                    to: '/projects/$projectId/messaging/$messageId',
                                    params: {
                                      projectId: projectId!,
                                      messageId: message.$id,
                                    },
                                  })
                                }}
                              >
                                <TableCell
                                  onClick={(e) => e.stopPropagation()}
                                  className="px-4 py-3"
                                >
                                  <Checkbox
                                    checked={selectedItems.has(message.$id)}
                                    onCheckedChange={() =>
                                      toggleItem(message.$id)
                                    }
                                  />
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  <Link
                                    to="/projects/$projectId/messaging/$messageId"
                                    params={{
                                      projectId: projectId!,
                                      messageId: message.$id,
                                    }}
                                    className="block"
                                  >
                                    <CopyableId id={message.$id} size="xs" />
                                  </Link>
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  <Link
                                    to="/projects/$projectId/messaging/$messageId"
                                    params={{
                                      projectId: projectId!,
                                      messageId: message.$id,
                                    }}
                                    className="block"
                                  >
                                    <p className="text-[13px] font-medium text-foreground">
                                      {getMessageContent(message)}
                                    </p>
                                  </Link>
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  <div className="flex items-center gap-2">
                                    <TypeIcon className="h-4 w-4 text-muted-foreground" />
                                    <span className="text-[13px] text-muted-foreground capitalize">
                                      {message.providerType}
                                    </span>
                                  </div>
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  {getMessageStatusBadge(
                                    message.status,
                                    message.deliveryErrors,
                                  )}
                                </TableCell>
                                <TableCell className="px-4 py-3 text-end">
                                  <Link
                                    to="/projects/$projectId/messaging/$messageId"
                                    params={{
                                      projectId: projectId!,
                                      messageId: message.$id,
                                    }}
                                    className="block"
                                  >
                                    {message.scheduledAt ? (
                                      <DateTooltip
                                        date={message.scheduledAt}
                                        className="text-[12px] text-muted-foreground font-mono"
                                      />
                                    ) : (
                                      <span className="text-[12px] text-muted-foreground/50 italic">
                                        N/A
                                      </span>
                                    )}
                                  </Link>
                                </TableCell>
                                <TableCell className="px-4 py-3 text-end">
                                  <Link
                                    to="/projects/$projectId/messaging/$messageId"
                                    params={{
                                      projectId: projectId!,
                                      messageId: message.$id,
                                    }}
                                    className="block"
                                  >
                                    {message.deliveredAt ? (
                                      <DateTooltip
                                        date={message.deliveredAt}
                                        className="text-[12px] text-muted-foreground font-mono"
                                      />
                                    ) : (
                                      <span className="text-[12px] text-muted-foreground/50 italic">
                                        N/A
                                      </span>
                                    )}
                                  </Link>
                                </TableCell>
                              </TableRow>
                            </MessageContextMenu>
                          )
                        },
                      )}
                    {activeTab === 'topics' &&
                      (currentData.items ?? []).map((topic: Models.Topic) => {
                        if (!topic?.$id) return null
                        const totalSubscribers =
                          (topic.emailTotal || 0) +
                          (topic.smsTotal || 0) +
                          (topic.pushTotal || 0)
                        return (
                          <TopicContextMenu
                            key={topic.$id}
                            projectId={projectId!}
                            topic={{ $id: topic.$id, name: topic.name }}
                          >
                            <TableRow
                              className={cn(
                                'cursor-pointer transition-colors border-b border-border/50',
                                selectedItems.has(topic.$id)
                                  ? 'bg-muted'
                                  : 'hover:bg-muted/30',
                              )}
                              onClick={(e) => {
                                const target = e.target as HTMLElement
                                if (
                                  target.closest('button') ||
                                  target.closest('[role="checkbox"]') ||
                                  target.closest('a')
                                ) {
                                  return
                                }
                                navigate({
                                  to: '/projects/$projectId/messaging/topics/$topicId',
                                  params: {
                                    projectId: projectId!,
                                    topicId: topic.$id,
                                  },
                                })
                              }}
                            >
                              <TableCell
                                onClick={(e) => e.stopPropagation()}
                                className="px-4 py-3"
                              >
                                <Checkbox
                                  checked={selectedItems.has(topic.$id)}
                                  onCheckedChange={() => toggleItem(topic.$id)}
                                />
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <Link
                                  to="/projects/$projectId/messaging/topics/$topicId"
                                  params={{
                                    projectId: projectId!,
                                    topicId: topic.$id,
                                  }}
                                  className="block"
                                >
                                  <CopyableId id={topic.$id} size="xs" />
                                </Link>
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <Link
                                  to="/projects/$projectId/messaging/topics/$topicId"
                                  params={{
                                    projectId: projectId!,
                                    topicId: topic.$id,
                                  }}
                                  className="block"
                                >
                                  <p className="text-[13px] font-medium text-foreground">
                                    {topic.name}
                                  </p>
                                </Link>
                              </TableCell>
                              <TableCell className="px-4 py-3 text-end">
                                <Link
                                  to="/projects/$projectId/messaging/topics/$topicId"
                                  params={{
                                    projectId: projectId!,
                                    topicId: topic.$id,
                                  }}
                                  className="block"
                                >
                                  <span className="text-[13px] text-muted-foreground">
                                    {totalSubscribers}
                                  </span>
                                </Link>
                              </TableCell>
                              <TableCell className="px-4 py-3 text-end">
                                <Link
                                  to="/projects/$projectId/messaging/topics/$topicId"
                                  params={{
                                    projectId: projectId!,
                                    topicId: topic.$id,
                                  }}
                                  className="block"
                                >
                                  {topic.$createdAt ? (
                                    <DateTooltip
                                      date={topic.$createdAt}
                                      className="text-[12px] text-muted-foreground font-mono"
                                    />
                                  ) : (
                                    <span className="text-[12px] text-muted-foreground/50 italic">
                                      N/A
                                    </span>
                                  )}
                                </Link>
                              </TableCell>
                            </TableRow>
                          </TopicContextMenu>
                        )
                      })}
                    {activeTab === 'providers' &&
                      (currentData.items ?? []).map(
                        (provider: Models.Provider) => {
                          if (!provider?.$id) return null
                          const TypeIcon = getMessageTypeIcon(provider.type)
                          return (
                            <ProviderContextMenu
                              key={provider.$id}
                              projectId={projectId!}
                              provider={{
                                $id: provider.$id,
                                name: provider.name,
                              }}
                            >
                              <TableRow
                                className={cn(
                                  'cursor-pointer transition-colors border-b border-border/50',
                                  selectedItems.has(provider.$id)
                                    ? 'bg-muted'
                                    : 'hover:bg-muted/30',
                                )}
                                onClick={(e) => {
                                  const target = e.target as HTMLElement
                                  if (
                                    target.closest('button') ||
                                    target.closest('[role="checkbox"]') ||
                                    target.closest('a')
                                  ) {
                                    return
                                  }
                                  navigate({
                                    to: '/projects/$projectId/messaging/providers/$providerId',
                                    params: {
                                      projectId: projectId!,
                                      providerId: provider.$id,
                                    },
                                  })
                                }}
                              >
                                <TableCell
                                  onClick={(e) => e.stopPropagation()}
                                  className="px-4 py-3"
                                >
                                  <Checkbox
                                    checked={selectedItems.has(provider.$id)}
                                    onCheckedChange={() =>
                                      toggleItem(provider.$id)
                                    }
                                  />
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  <Link
                                    to="/projects/$projectId/messaging/providers/$providerId"
                                    params={{
                                      projectId: projectId!,
                                      providerId: provider.$id,
                                    }}
                                    className="block"
                                  >
                                    <CopyableId id={provider.$id} size="xs" />
                                  </Link>
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  <Link
                                    to="/projects/$projectId/messaging/providers/$providerId"
                                    params={{
                                      projectId: projectId!,
                                      providerId: provider.$id,
                                    }}
                                    className="block"
                                  >
                                    <div className="flex items-center gap-2">
                                      <MessagingProviderIcon
                                        serviceKey={provider.provider}
                                        providerName={provider.name}
                                        providerType={
                                          provider.type as
                                            | 'email'
                                            | 'sms'
                                            | 'push'
                                        }
                                        size="sm"
                                        className="h-5 w-5"
                                      />
                                      <span className="text-[13px] font-medium text-foreground">
                                        {provider.name}
                                      </span>
                                    </div>
                                  </Link>
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  <div className="flex items-center gap-2">
                                    <TypeIcon className="h-4 w-4 text-muted-foreground" />
                                    <span className="text-[13px] text-muted-foreground capitalize">
                                      {provider.type}
                                    </span>
                                  </div>
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  {provider.enabled ? (
                                    <Badge
                                      variant="success"
                                      className="text-[10px] shrink-0"
                                    >
                                      {t('Enabled')}
                                    </Badge>
                                  ) : (
                                    <Badge
                                      variant="inactive"
                                      className="text-[10px] shrink-0"
                                    >
                                      {t('Disabled')}
                                    </Badge>
                                  )}
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  <Link
                                    to="/projects/$projectId/messaging/providers/$providerId"
                                    params={{
                                      projectId: projectId!,
                                      providerId: provider.$id,
                                    }}
                                    className="block"
                                  >
                                    <p className="text-[13px] text-foreground">
                                      {provider.name}
                                    </p>
                                  </Link>
                                </TableCell>
                              </TableRow>
                            </ProviderContextMenu>
                          )
                        },
                      )}
                  </TableBody>
                </Table>
              </div>
              <Pagination
                currentPage={displayedPage}
                totalItems={currentData.total}
                pageSize={pageSize}
                pageSizeOptions={[10, 25, 50, 100]}
                onPageChange={handlePageChange}
                onPageSizeChange={handlePageSizeChange}
                itemLabel={t(activeTab)}
              />
            </>
          ) : (
            <EmptyState
              icon={MessageSquare}
              title={
                searchValue
                  ? undefined
                  : activeTab === 'messages'
                    ? t('No messages yet') // pragma: allowlist secret
                    : activeTab === 'topics'
                      ? t('No topics yet')
                      : t('No providers yet')
              }
              description={
                searchValue
                  ? undefined
                  : activeTab === 'messages'
                    ? t('Create your first message to start sending notifications')
                    : activeTab === 'topics'
                      ? t('Create your first topic to organize subscribers')
                      : t('Create your first provider to send messages') // pragma: allowlist secret
              }
              isEmpty={!searchValue}
              hasFilters={!!searchValue}
              variant="card"
            />
          )}

        {/* Bulk Delete Action Bar */}
        {selectedItems.size > 0 && (
          <div className="fixed bottom-4 start-1/2 z-50 -translate-x-1/2">
            <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
              <Badge variant="info" className="h-6 shrink-0 px-2.5 text-[10px]">
                {selectedItems.size}{' '}
                {selectedItems.size > 1
                  ? t(activeTab)
                  : t(activeTab.slice(0, -1))}{' '}
                {t('selected')}
              </Badge>
              <div className="flex items-center gap-2">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setSelectedItems(new Set())}
                  className="h-8 text-xs"
                >
                  {t('Cancel')}
                </Button>
                <Button
                  variant="destructive"
                  size="sm"
                  onClick={handleBulkDelete}
                  disabled={bulkDeleteMutation.isPending}
                  className="h-8 gap-2"
                >
                  {t('Delete')}
                </Button>
              </div>
            </div>
          </div>
        )}

        {/* Bulk Delete Confirmation Dialog */}
        <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 text-start">
              <DialogTitle>{t('Delete')} {t(activeTab)}</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                {t('Are you sure you want to delete')} {selectedItems.size}{' '}
                {selectedItems.size > 1
                  ? t(activeTab)
                  : t(activeTab.slice(0, -1))}
                ? {t('This action cannot be undone.')}
              </DialogDescription>
            </DialogHeader>

            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setDeleteDialogOpen(false)}
                disabled={bulkDeleteMutation.isPending}
              >
                {t('Cancel')}
              </Button>
              <Button
                variant="destructive"
                onClick={confirmBulkDelete}
                disabled={bulkDeleteMutation.isPending}
              >
                {t('Delete')}
              </Button>
            </div>
          </DialogContent>
        </Dialog>

        <Dialog
          open={deliveryErrorLines !== null}
          onOpenChange={(open) => !open && setDeliveryErrorLines(null)}
        >
          <DialogContent className="sm:max-w-lg p-0">
            <DialogHeader className="px-6 pt-6 pb-4 text-start">
              <DialogTitle>{t('Message error')}</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                {t('The message failed to deliver. See the details below.')}
              </DialogDescription>
            </DialogHeader>
            <div className="border-t border-border" />
            <div className="px-6 pb-4 pt-0">
              <pre className="mt-4 max-h-[min(360px,50dvh)] overflow-auto whitespace-pre-wrap break-words rounded-md border border-border bg-muted/30 p-4 text-[12px] text-foreground">
                {(deliveryErrorLines ?? []).join('\n')}
              </pre>
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30 flex justify-end">
              <Button variant="outline" onClick={() => setDeliveryErrorLines(null)}>
                {t('Close')}
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      </div>
    </div>
  )
}
