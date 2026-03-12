import { useState, useMemo, useEffect } from 'react'
import { useParams, useLocation, useNavigate } from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import {
  Mail,
  Phone,
  Bell,
  List,
  LayoutGrid,
  MessageSquare,
} from 'lucide-react'
import {
  useProjectMessages,
  useProjectTopics,
  useProjectProviders,
  useProject,
  useOrganizationPlan,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { canWriteMessages, canWriteTopics, canWriteProviders } from '@/lib/console-access-checks'
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
import { sdk } from '@/lib/appwrite/sdk'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import type { Models } from '@appwrite.io/console'
import { MessagingProviderIcon } from '@/components/global/shared/MessagingProviderIcon'
import { MessageContextMenu } from './_components/MessageContextMenu'
import { TopicContextMenu } from './_components/TopicContextMenu'
import { ProviderContextMenu } from './_components/ProviderContextMenu'

export function View() {
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
  const [viewMode, setViewMode] = useState<'list' | 'grid'>('list')
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)
  const [selectedItems, setSelectedItems] = useState<Set<string>>(new Set())
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  // Fetch requested page (triggers load when user changes page) - active tab only
  const {
    total: messagesTotal,
    isLoading: messagesLoading,
    isFetching: messagesFetching,
  } = useProjectMessages(
    activeTab === 'messages' ? projectId : null,
    requestedPage - 1,
    pageSize,
    activeTab === 'messages' ? searchValue : undefined,
  )
  const {
    total: topicsTotal,
    isLoading: topicsLoading,
    isFetching: topicsFetching,
  } = useProjectTopics(
    activeTab === 'topics' ? projectId : null,
    requestedPage - 1,
    pageSize,
    activeTab === 'topics' ? searchValue : undefined,
  )
  const {
    total: providersTotal,
    isLoading: providersLoading,
    isFetching: providersFetching,
  } = useProjectProviders(
    activeTab === 'providers' ? projectId : null,
    requestedPage - 1,
    pageSize,
    activeTab === 'providers' ? searchValue : undefined,
  )

  // Fetch displayed page (what we show - stays until new page is ready) - active tab only
  const { messages, total: displayedMessagesTotal } = useProjectMessages(
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
  const activeLoading =
    activeTab === 'messages'
      ? messagesLoading
      : activeTab === 'topics'
        ? topicsLoading
        : providersLoading

  // Update displayed page only when requested page data is ready (no flash)
  useEffect(() => {
    if (!activeFetching && requestedPage !== displayedPage && !activeLoading) {
      setDisplayedPage(requestedPage)
    }
  }, [activeFetching, activeLoading, requestedPage, displayedPage])

  // Get current data based on active tab (use displayed data and displayed total for stable range)
  const currentData = useMemo(() => {
    if (activeTab === 'messages') {
      return {
        items: messages,
        total: displayedMessagesTotal ?? messagesTotal,
        isLoading: messagesLoading && messages.length === 0,
      }
    }
    if (activeTab === 'topics') {
      return {
        items: topics,
        total: displayedTopicsTotal ?? topicsTotal,
        isLoading: topicsLoading && topics.length === 0,
      }
    }
    if (activeTab === 'providers') {
      return {
        items: providers,
        total: displayedProvidersTotal ?? providersTotal,
        isLoading: providersLoading && providers.length === 0,
      }
    }
    return { items: [], total: 0, isLoading: false }
  }, [
    activeTab,
    messages,
    displayedMessagesTotal,
    messagesTotal,
    messagesLoading,
    topics,
    displayedTopicsTotal,
    topicsTotal,
    topicsLoading,
    providers,
    displayedProvidersTotal,
    providersTotal,
    providersLoading,
  ])

  const showLoading = currentData.isLoading

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
      ? "You don't have permission to create messages."
      : activeTab === 'topics'
        ? "You don't have permission to create topics."
        : "You don't have permission to create providers."
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
            projectSdk.messaging.deleteMessage({ messageId }),
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
        `Successfully deleted ${selectedItems.size} ${activeTab.slice(0, -1)}${selectedItems.size > 1 ? 's' : ''}`,
      )
      setSelectedItems(new Set())
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to delete items')
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
        new Set(items.map((item: { $id?: string }) => item?.$id).filter(Boolean) as string[]),
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
        label: 'Messages',
        to: '/projects/$projectId/messaging/',
        params: { projectId: projectId as string },
      },
      {
        id: 'topics',
        label: 'Topics',
        to: '/projects/$projectId/messaging/topics',
        params: { projectId: projectId as string },
      },
      {
        id: 'providers',
        label: 'Providers',
        to: '/projects/$projectId/messaging/providers',
        params: { projectId: projectId as string },
      },
    ],
    [projectId],
  )

  const getCreateLabel = () => {
    if (activeTab === 'messages') return 'Create message'
    if (activeTab === 'topics') return 'Create topic'
    if (activeTab === 'providers') return 'Create provider'
    return 'Create'
  }

  const handleCreateClick = () => {
    // TODO: Implement create dialogs
    toast.info('Create functionality coming soon')
  }

  const ViewToggle = () => (
    <div className="flex items-center gap-1 rounded-md border border-border bg-muted/30 p-0.5">
      <Button
        variant="ghost"
        size="sm"
        className={cn(
          'h-7 w-7 p-0',
          viewMode === 'list' ? 'bg-background' : 'hover:bg-transparent',
        )}
        onClick={() => setViewMode('list')}
      >
        <List className="h-4 w-4" />
      </Button>
      <Button
        variant="ghost"
        size="sm"
        className={cn(
          'h-7 w-7 p-0',
          viewMode === 'grid' ? 'bg-background' : 'hover:bg-transparent',
        )}
        onClick={() => setViewMode('grid')}
      >
        <LayoutGrid className="h-4 w-4" />
      </Button>
    </div>
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
    deliveryErrors?: Array<{ message?: string }> | Record<string, unknown>,
  ) => {
    if (status === 'sent') {
      return <Badge variant="success">Sent</Badge>
    }
    if (status === 'processing') {
      return <Badge variant="secondary">Processing</Badge>
    }
    if (status === 'failed') {
      return (
        <div className="flex items-center gap-2">
          <Badge variant="error">Failed</Badge>
          {deliveryErrors && (
            <Button
              variant="ghost"
              size="sm"
              className="h-6 text-xs"
              onClick={(e) => {
                e.stopPropagation()
                // TODO: Show error details modal
                toast.info('Error details: ' + JSON.stringify(deliveryErrors))
              }}
            >
              Details
            </Button>
          )}
        </div>
      )
    }
    if (status === 'draft') {
      return <Badge variant="secondary">Draft</Badge>
    }
    return <Badge variant="secondary">{status}</Badge>
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
    return 'No content'
  }

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title="Messaging"
        tabs={tabs}
        activeTab={activeTab}
        searchPlaceholder={
          activeTab === 'messages'
            ? 'Search messages...'
            : activeTab === 'topics'
              ? 'Search topics...'
              : 'Search providers...'
        }
        searchValue={searchValue}
        onSearchChange={handleSearchChange}
        createLabel={getCreateLabel()}
        onCreate={handleCreateClick}
        createDisabled={noCreatePermission}
        createDisabledTooltip={createPermissionTooltip}
        fullWidthBorder
        rightContent={<ViewToggle />}
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        {viewMode === 'list' ? (
          showLoading ? (
            <div className="rounded-lg border border-border bg-card py-12 text-center">
              <p className="text-[13px] text-muted-foreground">
                Loading {activeTab}...
              </p>
            </div>
          ) : (currentData.items ?? []).length > 0 ? (
            <>
              <div className="rounded-lg border border-border bg-card overflow-hidden">
                <Table>
                  <TableHeader>
                    <TableRow className="hover:bg-transparent border-b border-border">
                      <TableHead className="w-[40px] px-4">
                        <Checkbox
                          checked={
                            (currentData.items ?? []).length > 0 &&
                            selectedItems.size === (currentData.items ?? []).length
                          }
                          onCheckedChange={toggleAllItems}
                        />
                      </TableHead>
                      {activeTab === 'messages' ? (
                        <>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            Message ID
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            Message
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            Type
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            Status
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                            Scheduled at
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                            Delivered at
                          </TableHead>
                        </>
                      ) : activeTab === 'topics' ? (
                        <>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            Topic ID
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            Name
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                            Subscribers
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                            Created
                          </TableHead>
                        </>
                      ) : (
                        <>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            Provider ID
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            Provider
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            Type
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            Enabled
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            Name
                          </TableHead>
                        </>
                      )}
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {activeTab === 'messages' &&
                      (currentData.items ?? []).map((message: Models.Message) => {
                        if (!message?.$id) return null
                        const TypeIcon = getMessageTypeIcon(
                          message.providerType,
                        )
                        return (
                          <MessageContextMenu
                            key={message.$id}
                            projectId={projectId!}
                            message={{ $id: message.$id }}
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
                                onCheckedChange={() => toggleItem(message.$id)}
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
                            <TableCell className="px-4 py-3 text-right">
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
                            <TableCell className="px-4 py-3 text-right">
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
                      })}
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
                            <TableCell className="px-4 py-3 text-right">
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
                            <TableCell className="px-4 py-3 text-right">
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
                      (currentData.items ?? []).map((provider: Models.Provider) => {
                        if (!provider?.$id) return null
                        const TypeIcon = getMessageTypeIcon(provider.type)
                        return (
                          <ProviderContextMenu
                            key={provider.$id}
                            projectId={projectId!}
                            provider={{ $id: provider.$id, name: provider.name }}
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
                                onCheckedChange={() => toggleItem(provider.$id)}
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
                                    providerName={provider.name}
                                    providerType={
                                      provider.type as 'email' | 'sms' | 'push'
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
                                <Badge variant="success" className="gap-1">
                                  <span className="h-1.5 w-1.5 rounded-full bg-current" />
                                  Enabled
                                </Badge>
                              ) : (
                                <Badge variant="secondary">Disabled</Badge>
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
                      })}
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
                itemLabel={activeTab}
              />
            </>
          ) : (
            <EmptyState
              icon={MessageSquare}
              title={
                searchValue
                  ? undefined
                  : activeTab === 'messages'
                    ? 'No messages yet'
                    : activeTab === 'topics'
                      ? 'No topics yet'
                      : 'No providers yet'
              }
              description={
                searchValue
                  ? undefined
                  : activeTab === 'messages'
                    ? 'Create your first message to start sending notifications'
                    : activeTab === 'topics'
                      ? 'Create your first topic to organize subscribers'
                      : 'Create your first provider to send messages'
              }
              isEmpty={!searchValue}
              hasFilters={!!searchValue}
              variant="card"
            />
          )
        ) : (
          // Grid view - simplified for now, can be enhanced later
          <EmptyState
            icon={MessageSquare}
            title="Grid view coming soon"
            description="List view is currently available"
            isEmpty={true}
            variant="card"
          />
        )}

        {/* Bulk Delete Action Bar */}
        {selectedItems.size > 0 && (
          <div className="fixed bottom-4 left-1/2 z-50 -translate-x-1/2">
            <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
              <Badge variant="secondary" className="h-6 px-2.5">
                {selectedItems.size} {activeTab.slice(0, -1)}
                {selectedItems.size > 1 ? 's' : ''} selected
              </Badge>
              <div className="flex items-center gap-2">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setSelectedItems(new Set())}
                  className="h-8 text-xs"
                >
                  Cancel
                </Button>
                <Button
                  variant="destructive"
                  size="sm"
                  onClick={handleBulkDelete}
                  disabled={bulkDeleteMutation.isPending}
                  className="h-8 gap-2"
                >
                  Delete
                </Button>
              </div>
            </div>
          </div>
        )}

        {/* Bulk Delete Confirmation Dialog */}
        <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 text-left">
              <DialogTitle>Delete {activeTab}</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                Are you sure you want to delete {selectedItems.size}{' '}
                {activeTab.slice(0, -1)}
                {selectedItems.size > 1 ? 's' : ''}? This action cannot be
                undone.
              </DialogDescription>
            </DialogHeader>

            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setDeleteDialogOpen(false)}
                disabled={bulkDeleteMutation.isPending}
              >
                Cancel
              </Button>
              <Button
                variant="destructive"
                onClick={confirmBulkDelete}
                disabled={bulkDeleteMutation.isPending}
              >
                Delete
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      </div>
    </div>
  )
}
