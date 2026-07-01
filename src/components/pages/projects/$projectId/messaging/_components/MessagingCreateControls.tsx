import { useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { ChevronDown, Mail, Phone, Bell, Plus } from 'lucide-react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ID } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { sdk } from '@/lib/appwrite/sdk'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { toast } from 'sonner'
import { buttonVariants } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import {
  serviceHeaderIconOnlyButton,
  serviceHeaderShowLabel,
} from '@/components/pages/projects/$projectId/shared/service-header-container'

type ActiveTab = 'messages' | 'topics' | 'providers'

export function MessagingCreateControls({
  projectId,
  activeTab,
  disabled,
  disabledTooltip,
}: {
  projectId: string | undefined
  activeTab: ActiveTab
  disabled?: boolean
  disabledTooltip?: string
}) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [topicDialogOpen, setTopicDialogOpen] = useState(false)
  const [topicName, setTopicName] = useState('')

  const createDraftEmail = useMutation({
    mutationFn: async () => {
      if (!projectId) throw new Error('Missing project')
      const messageId = ID.unique()
      const projectSdk = sdk.forProject(projectId)
      return projectSdk.messaging.createEmail({
        messageId,
        subject: 'Untitled email',
        content: ' ',
        topics: [],
        users: [],
        targets: [],
        draft: true,
        html: false,
      })
    },
    onSuccess: (msg) => {
      queryClient.refetchQueries({ queryKey: ['messages', 'project', projectId] })
      toast.success('Draft message created')
      navigate({
        to: '/projects/$projectId/messaging/$messageId',
        params: { projectId: projectId!, messageId: msg.$id },
      })
    },
    onError: (e: Error) =>
      toast.error(getErrorMessage(e) || 'Could not create message'),
  })

  const createDraftSms = useMutation({
    mutationFn: async () => {
      if (!projectId) throw new Error('Missing project')
      const messageId = ID.unique()
      const projectSdk = sdk.forProject(projectId)
      return projectSdk.messaging.createSMS({
        messageId,
        content: ' ',
        topics: [],
        users: [],
        targets: [],
        draft: true,
      })
    },
    onSuccess: (msg) => {
      queryClient.refetchQueries({ queryKey: ['messages', 'project', projectId] })
      toast.success('Draft message created')
      navigate({
        to: '/projects/$projectId/messaging/$messageId',
        params: { projectId: projectId!, messageId: msg.$id },
      })
    },
    onError: (e: Error) =>
      toast.error(getErrorMessage(e) || 'Could not create message'),
  })

  const createDraftPush = useMutation({
    mutationFn: async () => {
      if (!projectId) throw new Error('Missing project')
      const messageId = ID.unique()
      const projectSdk = sdk.forProject(projectId)
      return projectSdk.messaging.createPush({
        messageId,
        title: 'Untitled notification',
        body: ' ',
        topics: [],
        users: [],
        targets: [],
        draft: true,
      })
    },
    onSuccess: (msg) => {
      queryClient.refetchQueries({ queryKey: ['messages', 'project', projectId] })
      toast.success('Draft message created')
      navigate({
        to: '/projects/$projectId/messaging/$messageId',
        params: { projectId: projectId!, messageId: msg.$id },
      })
    },
    onError: (e: Error) =>
      toast.error(getErrorMessage(e) || 'Could not create message'),
  })

  const createTopicMutation = useMutation({
    mutationFn: async (name: string) => {
      if (!projectId) throw new Error('Missing project')
      const projectSdk = sdk.forProject(projectId)
      return projectSdk.messaging.createTopic({
        topicId: ID.unique(),
        name: name.trim(),
      })
    },
    onSuccess: async (topic) => {
      await queryClient.refetchQueries({ queryKey: ['topics', 'project', projectId] })
      toast.success('Topic created')
      setTopicDialogOpen(false)
      setTopicName('')
      navigate({
        to: '/projects/$projectId/messaging/topics/$topicId',
        params: { projectId: projectId!, topicId: topic.$id },
      })
    },
    onError: (e: Error) =>
      toast.error(getErrorMessage(e) || 'Could not create topic'),
  })

  const busy =
    createDraftEmail.isPending ||
    createDraftSms.isPending ||
    createDraftPush.isPending

  if (!projectId) return null

  const label =
    activeTab === 'messages'
      ? 'Create message'
      : activeTab === 'topics'
        ? 'Create topic'
        : 'Create provider'

  if (disabled) {
    return (
      <TooltipProvider delayDuration={0}>
        <Tooltip>
          <TooltipTrigger asChild>
            <span
              className={cn(
                buttonVariants({ variant: 'brandCta' }),
                serviceHeaderIconOnlyButton,
                'inline-flex cursor-not-allowed items-center justify-center text-[13px] font-medium opacity-50 pointer-events-none',
              )}
>
              <Plus className="h-4 w-4 shrink-0" />
              <span className={serviceHeaderShowLabel}>{label}</span>
              <span className="sr-only @[640px]:hidden">{label}</span>
            </span>
          </TooltipTrigger>
          <TooltipContent side="bottom">
            <p>{disabledTooltip ?? 'You do not have permission to create this resource.'}</p>
          </TooltipContent>
        </Tooltip>
      </TooltipProvider>
    )
  }

  if (activeTab === 'topics') {
    return (
      <>
        <Button
          variant="brandCta"
          className={cn(serviceHeaderIconOnlyButton, 'text-[13px] font-medium')}
          onClick={() => setTopicDialogOpen(true)}
          aria-label="Create topic"
>
          <Plus className="h-4 w-4 shrink-0" />
          <span className={serviceHeaderShowLabel}>Create topic</span>
          <span className="sr-only @[640px]:hidden">Create topic</span>
        </Button>
        <Dialog open={topicDialogOpen} onOpenChange={setTopicDialogOpen}>
          <DialogContent
            className="sm:max-w-md p-0"
>
            <DialogHeader className="px-6 pt-6 pb-4 text-start">
              <DialogTitle>Create topic</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                Topics group subscribers for email, SMS, or push.
              </DialogDescription>
            </DialogHeader>
            <div className="border-t border-border" />
            <div className="px-6 py-4 space-y-3">
              <div>
                <Label htmlFor="topic-name" className="text-[13px]">
                  Name
                </Label>
                <Input
                  id="topic-name"
                  value={topicName}
                  onChange={(e) => setTopicName(e.target.value)}
                  placeholder="Marketing"
                  className="mt-1.5 h-9"
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' && topicName.trim()) {
                      createTopicMutation.mutate(topicName)
                    }
                  }}
                />
              </div>
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setTopicDialogOpen(false)}
                disabled={createTopicMutation.isPending}
>
                Cancel
              </Button>
              <Button
                onClick={() => createTopicMutation.mutate(topicName)}
                disabled={!topicName.trim() || createTopicMutation.isPending}
>
                Create
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      </>
    )
  }

  if (activeTab === 'providers') {
    return (
      <Button
        variant="brandCta"
        className={cn(serviceHeaderIconOnlyButton, 'text-[13px] font-medium')}
        onClick={() =>
          navigate({
            to: '/projects/$projectId/messaging/providers/create',
            params: { projectId },
          })
        }
        aria-label="Create provider"
>
        <Plus className="h-4 w-4 shrink-0" />
        <span className={serviceHeaderShowLabel}>Create provider</span>
        <span className="sr-only @[640px]:hidden">Create provider</span>
      </Button>
    )
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="brandCta"
          className={cn(
            serviceHeaderIconOnlyButton,
            'text-[13px] font-medium @[640px]:gap-1.5',
          )}
          disabled={busy}
          aria-label="Create message"
>
          <Plus className="h-4 w-4 shrink-0" />
          <span className={serviceHeaderShowLabel}>Create message</span>
          <span className="sr-only @[640px]:hidden">Create message</span>
          <ChevronDown className="h-3.5 w-3.5 shrink-0 opacity-70" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent
        align="end"
        className="w-48"
>
        <DropdownMenuItem
          onSelect={() => createDraftEmail.mutate()}
          disabled={busy}
>
          <Mail className="me-2 h-4 w-4 text-muted-foreground" />
          Email
        </DropdownMenuItem>
        <DropdownMenuItem
          onSelect={() => createDraftSms.mutate()}
          disabled={busy}
>
          <Phone className="me-2 h-4 w-4 text-muted-foreground" />
          SMS
        </DropdownMenuItem>
        <DropdownMenuItem
          onSelect={() => createDraftPush.mutate()}
          disabled={busy}
>
          <Bell className="me-2 h-4 w-4 text-muted-foreground" />
          Push
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

