import { useState, useMemo } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { ArrowLeft, Hash, Trash2 } from 'lucide-react'
import { useTopic } from '@/lib/react-query/hooks'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { getErrorMessage } from '@/lib/utils/error-formatting'

export function View() {
  const { projectId, topicId } = useParams({
    strict: false,
  })
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  // Fetch topic
  const { data: topic, isLoading: topicLoading } = useTopic(projectId, topicId)

  const [name, setName] = useState('')
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  // Initialize form when topic loads
  useMemo(() => {
    if (topic) {
      setName(topic.name || '')
    }
  }, [topic])

  // Update name mutation
  const updateNameMutation = useMutation({
    mutationFn: async (name: string) => {
      if (!projectId || !topicId) {
        throw new Error('Project ID and Topic ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      await projectSdk.messaging.updateTopic({
        topicId,
        name,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['topic', 'project', projectId, topicId],
      })
      toast.success('Topic name updated successfully')
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to update topic name')
    },
  })

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
      to: '/projects/$projectId/messaging/topics/$topicId',
      params: { projectId: projectId!, topicId: topicId! },
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
    // eslint-disable-next-line react-hooks/exhaustive-deps
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
        fullWidthBorder
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6 pt-4 sm:pt-6">
        <div className="space-y-6">
          {/* Update Name Section - First Card */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Name
              </h3>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <p className="text-[13px] text-muted-foreground">
                Update your topic's display name. This will be visible to all
                team members.
              </p>
              <Input
                id="topic-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Topic name"
                className="mt-3 h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
              />
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30">
              <Button
                size="sm"
                className="h-9 text-[13px]"
                disabled={
                  name.trim() === topic.name ||
                  !name.trim() ||
                  updateNameMutation.isPending
                }
                onClick={() => updateNameMutation.mutate(name)}
              >
                Update
              </Button>
            </div>
          </div>

          {/* Overview Section */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Overview
              </h3>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <div className="space-y-4">
                <div className="flex items-start justify-between gap-4">
                  <div className="flex-1 min-w-0">
                    <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5">
                      Topic ID
                    </p>
                    <CopyableId id={topic.$id} size="sm" />
                  </div>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5">
                      Created
                    </p>
                    {topic.$createdAt ? (
                      <DateTooltip
                        date={topic.$createdAt}
                        className="text-[13px] text-foreground"
                        showFormattedDate
                      />
                    ) : (
                      <span className="text-[13px] text-muted-foreground/50 italic">
                        N/A
                      </span>
                    )}
                  </div>
                  <div>
                    <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5">
                      Updated
                    </p>
                    <DateTooltip
                      date={topic.$updatedAt || topic.$createdAt}
                      className="text-[13px] text-foreground"
                      showFormattedDate
                    />
                  </div>
                </div>
              </div>
            </div>
          </div>

          {/* Delete Topic */}
          <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Delete Topic
              </h3>
            </div>
            <div className="border-t border-destructive/20" />
            <div className="px-6 py-4">
              <p className="text-[13px] text-muted-foreground">
                Permanently delete this topic and all its subscribers. This
                action cannot be undone.
              </p>

              {/* Topic Info Summary */}
              {topic && (
                <div className="flex items-center gap-3 mt-4">
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                    <Hash className="h-5 w-5 text-muted-foreground" />
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-[14px] font-medium text-foreground truncate">
                      {topic.name}
                    </p>
                    {topic.emailTotal !== undefined ||
                    topic.smsTotal !== undefined ||
                    topic.pushTotal !== undefined ? (
                      <p className="text-[12px] text-muted-foreground">
                        {(topic.emailTotal || 0) +
                          (topic.smsTotal || 0) +
                          (topic.pushTotal || 0)}{' '}
                        subscriber
                        {(topic.emailTotal || 0) +
                          (topic.smsTotal || 0) +
                          (topic.pushTotal || 0) !==
                        1
                          ? 's'
                          : ''}
                      </p>
                    ) : null}
                  </div>
                </div>
              )}
            </div>

            <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
              <Button
                variant="destructive"
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => setDeleteDialogOpen(true)}
                disabled={deleteTopicMutation.isPending}
              >
                <Trash2 className="mr-1.5 h-4 w-4" />
                Delete topic
              </Button>
            </div>
          </div>
        </div>

        {/* Delete Confirmation Dialog */}
        <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 text-left">
              <DialogTitle>Delete Topic</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                Are you sure you want to delete this topic? This action cannot
                be undone.
              </DialogDescription>
            </DialogHeader>

            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setDeleteDialogOpen(false)}
                disabled={deleteTopicMutation.isPending}
              >
                Cancel
              </Button>
              <Button
                variant="destructive"
                onClick={() => deleteTopicMutation.mutate()}
                disabled={deleteTopicMutation.isPending}
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
