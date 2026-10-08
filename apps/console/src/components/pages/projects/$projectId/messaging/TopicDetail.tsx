import { useState, useMemo, useEffect } from 'react'
import { useParams, useNavigate, useLocation } from '@tanstack/react-router'
import { ArrowLeft, Hash, Mail, Phone, Bell } from 'lucide-react'
import {
  useTopic,
  useTopicSubscribers,
  fetchUser,
  useProject,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { canShowTopicSettingsTab } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { CopyableId } from '@/components/global/shared/CopyableId'
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
  TableRow,
} from '@/components/ui/table'

import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import { sdk } from '@/lib/appwrite/sdk'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import type { Models } from '@appwrite.io/console'

export function TopicDetailView() {
  const t = useT()
  const { projectId, topicId } = useParams({
    strict: false,
  })
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()

  // Fetch topic
  const { data: topic, isLoading: topicLoading } = useTopic(projectId, topicId)
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const showSettingsTab = canShowTopicSettingsTab(access, features)

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

  // Delete topic mutation
  useMutation({
    mutationFn: async () => {
      if (!projectId || !topicId) {
        throw new Error('Project ID and Topic ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      await projectSdk.messaging.deleteTopic({ topicId })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['topics', 'project', projectId],
      })
      toast.success(t('Topic deleted successfully'))
      navigate({
        to: '/projects/$projectId/messaging/topics',
        params: { projectId: projectId! },
      })
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to delete topic'))
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
        label: t('Subscribers'),
        to: '/projects/$projectId/messaging/topics/$topicId',
        params: {
          projectId: projectId as string,
          topicId: topicId as string,
        },
      },
      ...(showSettingsTab
        ? [
            {
              id: 'settings' as const,
              label: t('Settings'),
              to: '/projects/$projectId/messaging/topics/$topicId/settings',
              params: {
                projectId: projectId as string,
                topicId: topicId as string,
              },
            },
          ]
        : []),
    ],
    [projectId, topicId, showSettingsTab, t],
  )

  const getTypeIcon = (type: string) => {
    if (type === 'email') return Mail
    if (type === 'sms') return Phone
    if (type === 'push') return Bell
    return Hash
  }

  if (topicLoading) {
    return (
      <div className="flex items-center justify-center py-16">
        <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
          <p className="text-[13px] text-muted-foreground">{t('Loading topic...')}</p>
        </div>
      </div>
    )
  }

  if (!topic) {
    return (
      <div className="flex items-center justify-center py-16">
        <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
          <p className="text-[13px] text-muted-foreground">{t('Topic not found')}</p>
        </div>
      </div>
    )
  }

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={
          <div className="flex min-w-0 items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              className="h-7 w-7 p-0"
              onClick={handleBack}
            >
              <ArrowLeft className="h-4 w-4" />
            </Button>
            <span className="truncate">{topic.name}</span>
            <CopyableId id={topic.$id} size="xs" className="shrink-0" />
          </div>
        }
        tabs={tabs}
        activeTab={activeTab}
        searchPlaceholder={
          activeTab === 'subscribers' ? t('Search subscribers...') : undefined
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
        createLabel={activeTab === 'subscribers' ? t('Add subscriber') : undefined}
        onCreate={
          activeTab === 'subscribers'
            ? () => {
                // TODO: Implement add subscriber dialog
                toast.info(t('Add subscriber functionality coming soon'))
              }
            : undefined
        }
        fullWidthBorder
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6 pt-4 sm:pt-6">
        {activeTab === 'subscribers' ? (
          <>
            {subscribersLoading || usersLoading ? (
              <div className="rounded-lg border border-border bg-card py-12 text-center">
                <p className="text-[13px] text-muted-foreground">
                  {t('Loading subscribers...')}
                </p>
              </div>
            ) : subscribers.length > 0 ? (
              <>
                <div className="rounded-lg border border-border bg-card overflow-hidden">
                  <Table>
                    <TableHeader>
                      <TableRow className="hover:bg-transparent border-b border-border">
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                          {t('Subscriber ID')}
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                          {t('Name')}
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                          {t('Target ID')}
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                          {t('Target')}
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                          {t('Type')}
                        </TableHead>
                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                          {t('Created')}
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
                  itemLabel={t('subscribers')}
                />
              </>
            ) : (
              <EmptyState
                icon={Hash}
                title={t('No subscribers found')}
                description={t('Add subscribers to this topic')}
                isEmpty={!searchValue}
                hasFilters={!!searchValue}
                variant="card"
              />
            )}
          </>
        ) : (
          <div className="rounded-lg border border-border bg-card py-12 text-center">
            <p className="text-[13px] text-muted-foreground">
              {t('Settings tab coming soon')}
            </p>
          </div>
        )}
      </div>
    </div>
  )
}
