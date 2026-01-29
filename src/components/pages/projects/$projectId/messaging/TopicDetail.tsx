import { useState, useMemo, useEffect } from 'react'
import { useParams, useNavigate, useLocation } from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import {
  ArrowLeft,
  Hash,
  Settings,
  Plus,
  Trash2,
  Mail,
  Phone,
  Bell,
} from 'lucide-react'
import {
  useTopic,
  useTopicSubscribers,
  fetchUser,
} from '@/lib/react-query/hooks'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import type { Models } from '@appwrite.io/console'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'

export function TopicDetailView() {
  const { projectId, topicId } = useParams({
    strict: false,
  })
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()

  // Fetch topic
  const { data: topic, isLoading: topicLoading } = useTopic(projectId, topicId)

  const [searchValue, setSearchValue] = useState('')
  const [currentPage, setCurrentPage] = useState(1)
  const [pageSize, setPageSize] = useState(25)

  // Convert 1-indexed page to 0-indexed for API
  const pageIndexed = currentPage - 1

  // Fetch subscribers
  const {
    subscribers,
    total: subscribersTotal,
    isLoading: subscribersLoading,
  } = useTopicSubscribers(
    projectId,
    topicId,
    pageIndexed,
    pageSize,
    searchValue,
  )

  // Fetch users for subscribers (from target.userId)
  const [usersById, setUsersById] = useState<
    Record<string, Models.User | null>
  >({})
  const [usersLoading, setUsersLoading] = useState(true)

  useEffect(() => {
    if (!projectId || subscribers.length === 0) {
      setUsersLoading(false)
      return
    }

    setUsersLoading(true)
    const userIds = new Set<string>()

    // Collect user IDs from subscriber targets
    subscribers.forEach((subscriber) => {
      if (subscriber.target?.userId) {
        userIds.add(subscriber.target.userId)
      }
    })

    if (userIds.size === 0) {
      setUsersLoading(false)
      return
    }

    Promise.allSettled(
      Array.from(userIds).map((userId) =>
        fetchUser(projectId, userId).catch(() => null),
      ),
    ).then((userResults) => {
      const usersMap: Record<string, Models.User | null> = {}
      Array.from(userIds).forEach((userId, index) => {
        const result = userResults[index]
        usersMap[userId] = result.status === 'fulfilled' ? result.value : null
      })
      setUsersById(usersMap)
      setUsersLoading(false)
    })
  }, [projectId, subscribers])

  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  // Delete topic mutation
  const deleteTopicMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !topicId) {
        throw new Error('Project ID and Topic ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      await projectSdk.messaging.deleteTopic({ topicId })
    },
    onSuccess: async () => {
      // Refetch topics list so the list view shows updated data (uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['topics', 'project', projectId],
      })
      toast.success('Topic deleted successfully')
      navigate({
        to: '/projects/$projectId/messaging/topics',
        params: { projectId: projectId! },
      })
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to delete topic')
    },
  })

  const handleBack = () => {
    navigate({
      to: '/projects/$projectId/messaging/topics',
      params: { projectId: projectId! },
    })
  }

  // Derive active tab from pathname
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
          topicId: topicId as string,
        },
      },
      {
        id: 'settings',
        label: 'Settings',
        to: '/projects/$projectId/messaging/topics/$topicId/settings',
        params: {
          projectId: projectId as string,
          topicId: topicId as string,
        },
      },
    ],
    [projectId, topicId],
  )

  const getTypeIcon = (type: string) => {
    if (type === 'email') return Mail
    if (type === 'sms') return Phone
    if (type === 'push') return Bell
    return Hash
  }

  if (topicLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
          <p className="text-[13px] text-muted-foreground">Loading topic...</p>
        </div>
      </div>
    )
  }

  if (!topic) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
          <p className="text-[13px] text-muted-foreground">Topic not found</p>
        </div>
      </div>
    )
  }

  const totalSubscribers =
    (topic.emailTotal || 0) + (topic.smsTotal || 0) + (topic.pushTotal || 0)

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={
          <div className="flex items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              className="h-7 w-7 p-0"
              onClick={handleBack}
            >
              <ArrowLeft className="h-4 w-4" />
            </Button>
            <span>{topic.name}</span>
          </div>
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
                setCurrentPage(1)
              }
            : undefined
        }
        createLabel={activeTab === 'subscribers' ? 'Add subscriber' : undefined}
        onCreate={
          activeTab === 'subscribers'
            ? () => {
                // TODO: Implement add subscriber dialog
                toast.info('Add subscriber functionality coming soon')
              }
            : undefined
        }
        fullWidthBorder
      />

      <div className="mx-auto w-full max-w-7xl flex-1 overflow-y-auto px-4 pb-4 sm:px-6 sm:pb-6 pt-4 sm:pt-6">
        {activeTab === 'subscribers' ? (
          <>
            {subscribersLoading || usersLoading ? (
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
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                          Created
                        </TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {subscribers.map((subscriber) => {
                        const target = subscriber.target
                        const user = target?.userId
                          ? usersById[target.userId]
                          : null
                        const TypeIcon = getTypeIcon(subscriber.providerType)

                        return (
                          <TableRow
                            key={subscriber.$id}
                            className="border-b border-border/50"
                          >
                            <TableCell className="px-4 py-3">
                              <CopyableId id={subscriber.$id} size="xs" />
                            </TableCell>
                            <TableCell className="px-4 py-3">
                              <span className="text-[13px] text-foreground">
                                {user?.name || user?.email || 'N/A'}
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
                            <TableCell className="px-4 py-3 text-right">
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
                          </TableRow>
                        )
                      })}
                    </TableBody>
                  </Table>
                </div>
                <Pagination
                  currentPage={currentPage}
                  totalItems={subscribersTotal}
                  pageSize={pageSize}
                  pageSizeOptions={[10, 25, 50, 100]}
                  onPageChange={setCurrentPage}
                  onPageSizeChange={(size) => {
                    setPageSize(size)
                    setCurrentPage(1)
                  }}
                  itemLabel="subscribers"
                />
              </>
            ) : (
              <EmptyState
                icon={Hash}
                title="No subscribers found"
                description="Add subscribers to this topic"
                isEmpty={!searchValue}
                hasFilters={!!searchValue}
                variant="card"
              />
            )}
          </>
        ) : (
          <div className="rounded-lg border border-border bg-card py-12 text-center">
            <p className="text-[13px] text-muted-foreground">
              Settings tab coming soon
            </p>
          </div>
        )}
      </div>
    </div>
  )
}
