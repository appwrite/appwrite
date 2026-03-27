import {
  createContext,
  memo,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react'
import { useLocation, useParams } from '@tanstack/react-router'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { toast } from 'sonner'
import {
  ChevronsUpDown,
  Loader2,
  MessageSquare,
  Plus,
  Send,
  Trash2,
  X,
  Lightbulb,
  GripVertical,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { StreamingMarkdown } from '@/components/global/shared/StreamingMarkdown'
import {
  useAssistantConversations,
  useAssistantMessages,
  useCreateAssistantConversation,
  useCreateAssistantMessage,
  useDeleteAssistantConversation,
  type AssistantConversation,
  type AssistantMessage,
} from '@/lib/react-query/hooks'

interface AIChatContextValue {
  isOpen: boolean
  activeConversationId: string | null
  openChat: () => void
  closeChat: () => void
  toggleChat: () => void
  setActiveConversationId: (conversationId: string | null) => void
}

const AIChatContext = createContext<AIChatContextValue | null>(null)
const OPEN_STATE_STORAGE_KEY = 'ai-chat-panel-open'
const AUTH_ROUTE_PATHNAMES = new Set([
  '/sign-in',
  '/sign-up',
  '/recovery',
  '/mfa',
  '/join',
  '/sign-out',
  '/verify-email',
])

function isAssistantBlockedPath(pathname: string): boolean {
  return AUTH_ROUTE_PATHNAMES.has(pathname)
}

export function AIChatProvider({ children }: { children: React.ReactNode }) {
  const location = useLocation()
  const isAssistantBlocked = useMemo(
    () => isAssistantBlockedPath(location.pathname),
    [location.pathname],
  )
  const [isOpen, setIsOpen] = useState(() => {
    if (typeof window === 'undefined') return false
    return localStorage.getItem(OPEN_STATE_STORAGE_KEY) === 'true'
  })
  const [activeConversationId, setActiveConversationId] = useState<string | null>(
    null,
  )

  const openChat = useCallback(() => {
    if (isAssistantBlocked) return
    setIsOpen(true)
  }, [isAssistantBlocked])
  const closeChat = useCallback(() => setIsOpen(false), [])
  const toggleChat = useCallback(() => {
    if (isAssistantBlocked) return
    setIsOpen((prev) => !prev)
  }, [isAssistantBlocked])

  useEffect(() => {
    if (isAssistantBlocked && isOpen) {
      setIsOpen(false)
    }
  }, [isAssistantBlocked, isOpen])

  useEffect(() => {
    if (typeof window === 'undefined') return
    localStorage.setItem(OPEN_STATE_STORAGE_KEY, isOpen ? 'true' : 'false')
  }, [isOpen])

  return (
    <AIChatContext.Provider
      value={{
        isOpen,
        activeConversationId,
        openChat,
        closeChat,
        toggleChat,
        setActiveConversationId,
      }}
    >
      {children}
    </AIChatContext.Provider>
  )
}

export function useAIChat() {
  const context = useContext(AIChatContext)
  if (!context) {
    // Return no-op functions if used outside provider
    return {
      isOpen: false,
      activeConversationId: null,
      openChat: () => {},
      closeChat: () => {},
      toggleChat: () => {},
      setActiveConversationId: () => {},
    }
  }
  return context
}

const suggestedQuestions = [
  'How do I create a new database?',
  'How do I set up authentication?',
  'How do I upload files to storage?',
  'How do I deploy a function?',
]

const MIN_WIDTH = 320
const MAX_WIDTH = 600
const DEFAULT_WIDTH = 400
const STORAGE_KEY = 'ai-chat-panel-width'

interface AssistantMessageRowProps {
  messageId: string
  role: string
  messageText: string
  deferCodeBlocks: boolean
}

const AssistantMessageRow = memo(
  function AssistantMessageRow({
    role,
    messageText,
    deferCodeBlocks,
  }: AssistantMessageRowProps) {
    const isUserMessage = role.toLowerCase() === 'user'

    return (
      <div className="space-y-1.5">
        <div
          className={cn('flex', isUserMessage ? 'justify-end' : 'justify-start')}
        >
          <div
            className={cn(
              'max-w-[88%] rounded-md px-2.5 py-1.5 text-[13px]',
              isUserMessage && 'whitespace-pre-wrap',
              isUserMessage
                ? 'bg-primary text-primary-foreground'
                : 'bg-card text-foreground',
            )}
          >
            {isUserMessage ? (
              messageText
            ) : (
              <StreamingMarkdown
                content={messageText}
                deferCodeBlocks={deferCodeBlocks}
              />
            )}
          </div>
        </div>
      </div>
    )
  },
  (prev, next) =>
    prev.messageId === next.messageId &&
    prev.role === next.role &&
    prev.messageText === next.messageText &&
    prev.deferCodeBlocks === next.deferCodeBlocks,
)

export function AIChatPanel() {
  const overrides = useDebugOverrides()
  const {
    isOpen,
    closeChat,
    activeConversationId,
    setActiveConversationId,
  } = useAIChat()
  const params = useParams({ strict: false }) as {
    projectId?: string
    orgId?: string
    teamId?: string
  }
  const location = useLocation()
  const isAssistantBlocked = useMemo(
    () => isAssistantBlockedPath(location.pathname),
    [location.pathname],
  )
  const showPanel = overrides.showAIAssistant
  const [input, setInput] = useState('')
  const [conversationsPopoverOpen, setConversationsPopoverOpen] = useState(false)
  const [width, setWidth] = useState(() => {
    if (typeof window !== 'undefined') {
      const saved = localStorage.getItem(STORAGE_KEY)
      if (saved) {
        const parsed = parseInt(saved, 10)
        if (!isNaN(parsed) && parsed >= MIN_WIDTH && parsed <= MAX_WIDTH) {
          return parsed
        }
      }
    }
    return DEFAULT_WIDTH
  })
  const [isResizing, setIsResizing] = useState(false)
  const messagesEndRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLTextAreaElement>(null)
  const panelRef = useRef<HTMLDivElement>(null)
  const {
    data: conversationsData,
    isLoading: conversationsLoading,
    isFetching: conversationsFetching,
  } = useAssistantConversations()
  const conversations: AssistantConversation[] = conversationsData ?? []
  const createConversationMutation = useCreateAssistantConversation()
  const deleteConversationMutation = useDeleteAssistantConversation()
  const createMessageMutation = useCreateAssistantMessage()

  const activeConversation = useMemo(
    () => conversations.find((conversation) => conversation.$id === activeConversationId),
    [activeConversationId, conversations],
  )

  const { data: messagesData, isFetching: messagesFetching } = useAssistantMessages(
    activeConversationId,
  )
  const messages: AssistantMessage[] = messagesData ?? []
  const latestMessageId = messages[messages.length - 1]?.$id

  const isConversationRunning = useMemo(() => {
    if (!activeConversation) return false
    const status = activeConversation.status?.toLowerCase()
    const lockState = activeConversation.lockState?.toLowerCase()
    return status === 'running' || status === 'queued' || lockState === 'locked'
  }, [activeConversation])

  const isThinking =
    createMessageMutation.isPending || isConversationRunning || messagesFetching

  // Auto-scroll to bottom when new messages arrive
  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [messages])

  // Focus input when panel opens
  useEffect(() => {
    if (isOpen) {
      setTimeout(() => inputRef.current?.focus(), 300)
    }
  }, [isOpen])

  // Save width to localStorage when it changes
  useEffect(() => {
    if (typeof window !== 'undefined') {
      localStorage.setItem(STORAGE_KEY, width.toString())
    }
  }, [width])

  // Pick initial conversation if none is selected
  useEffect(() => {
    if (
      activeConversationId &&
      conversations.some(
        (conversation: AssistantConversation) => conversation.$id === activeConversationId,
      )
    ) {
      return
    }

    if (conversations.length > 0) {
      setActiveConversationId(conversations[0].$id)
      return
    }

    setActiveConversationId(null)
  }, [activeConversationId, conversations, setActiveConversationId])

  // Handle resize drag
  const handleMouseDown = useCallback((e: React.MouseEvent) => {
    e.preventDefault()
    setIsResizing(true)
  }, [])

  useEffect(() => {
    if (!isResizing) return

    const handleMouseMove = (e: MouseEvent) => {
      if (!panelRef.current) return

      // Calculate new width based on mouse position from right edge of viewport
      const newWidth = window.innerWidth - e.clientX
      const clampedWidth = Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, newWidth))
      setWidth(clampedWidth)
    }

    const handleMouseUp = () => {
      setIsResizing(false)
    }

    document.addEventListener('mousemove', handleMouseMove)
    document.addEventListener('mouseup', handleMouseUp)

    // Add cursor style to body during resize
    document.body.style.cursor = 'col-resize'
    document.body.style.userSelect = 'none'

    return () => {
      document.removeEventListener('mousemove', handleMouseMove)
      document.removeEventListener('mouseup', handleMouseUp)
      document.body.style.cursor = ''
      document.body.style.userSelect = ''
    }
  }, [isResizing])

  if (!showPanel || isAssistantBlocked) return null

  const handleCreateConversation = async () => {
    if (!params.projectId) {
      toast.error('Open a project to create a new conversation.')
      return
    }

    try {
      const conversation = await createConversationMutation.mutateAsync({
        projectId: params.projectId,
        title: 'New conversation',
      })
      setActiveConversationId(conversation.$id)
      setConversationsPopoverOpen(false)
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to create conversation'))
    }
  }

  const handleDeleteConversation = async (conversationId: string) => {
    try {
      await deleteConversationMutation.mutateAsync(conversationId)
      if (conversationId === activeConversationId) {
        const nextConversation = conversations.find((c) => c.$id !== conversationId)
        setActiveConversationId(nextConversation?.$id ?? null)
      }
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to delete conversation'))
    }
  }

  const handleSend = async (content: string = input) => {
    const trimmed = content.trim()
    if (!trimmed || createMessageMutation.isPending) return
    setInput('')

    let conversationId = activeConversationId

    try {
      if (!conversationId) {
        if (!params.projectId) {
          toast.error('Open a project to start a new conversation.')
          return
        }

        const createdConversation = await createConversationMutation.mutateAsync({
          projectId: params.projectId,
          title: makeConversationTitle(trimmed),
        })
        conversationId = createdConversation.$id
        setActiveConversationId(createdConversation.$id)
      }
      if (!conversationId) return

      await createMessageMutation.mutateAsync({
        conversationId,
        contentText: trimmed,
        context: {
          contextTeamId: params.orgId ?? params.teamId,
          contextProjectId: params.projectId ?? activeConversation?.projectId,
          contextOrganizationId: params.orgId,
          contextPagePath: location.pathname,
          contextPageTitle:
            typeof document !== 'undefined' ? document.title : undefined,
          contextPageUrl:
            typeof window !== 'undefined' ? window.location.href : undefined,
        },
        continueRun: true,
      })
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to send message'))
    }
  }

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault()
      handleSend()
    }
  }

  // Don't render anything if not open
  if (!isOpen) return null

  return (
    <div
      ref={panelRef}
      style={{ width: `${width}px` }}
      className={cn(
        'relative flex h-full shrink-0 flex-col border-l border-border bg-background',
      )}
    >
      {/* Resize Handle */}
      <div
        onMouseDown={handleMouseDown}
        className={cn(
          'absolute left-0 top-0 z-10 flex h-full w-1.5 cursor-col-resize items-center justify-center transition-colors hover:bg-primary/20',
          isResizing && 'bg-primary/30',
        )}
      >
        <div className="absolute left-0 top-1/2 -translate-y-1/2 opacity-0 transition-opacity hover:opacity-100">
          <GripVertical className="h-6 w-6 text-muted-foreground" />
        </div>
      </div>

      <div className="flex h-full min-h-0">
        <div className="flex min-w-0 flex-1 flex-col">
          <div className="flex h-14 min-h-14 shrink-0 items-center justify-between border-b border-border px-3">
            <div className="min-w-0">
              <div className="flex items-center gap-1">
                <Popover
                  open={conversationsPopoverOpen}
                  onOpenChange={setConversationsPopoverOpen}
                >
                  <PopoverTrigger asChild>
                    <Button
                      type="button"
                      variant="ghost"
                      className="h-8 max-w-[248px] justify-start px-1.5 text-left"
                    >
                      <div className="flex min-w-0 items-center gap-1.5">
                        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-primary/10">
                          <MessageSquare className="h-3.5 w-3.5 text-primary" />
                        </div>
                        <div className="min-w-0">
                          <p className="truncate text-[13px] font-semibold text-foreground">
                            {activeConversation?.title || 'New conversation'}
                          </p>
                        </div>
                        <ChevronsUpDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                      </div>
                    </Button>
                  </PopoverTrigger>

                  <PopoverContent align="start" className="w-[300px] p-0">
                    <div className="border-b border-border px-2 py-1.5">
                      <p className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                        Conversations
                      </p>
                    </div>

                    <div className="max-h-[300px] overflow-y-auto p-1.5">
                    {conversationsLoading || conversationsFetching ? (
                      <div className="flex items-center gap-1.5 px-1.5 py-2 text-[11px] text-muted-foreground">
                        <Loader2 className="h-3.5 w-3.5 animate-spin" />
                        Loading conversations...
                      </div>
                    ) : conversations.length === 0 ? (
                      <div className="px-1.5 py-2 text-[11px] text-muted-foreground">
                        No conversations yet.
                      </div>
                    ) : (
                      <div className="space-y-0.5">
                        {conversations.map((conversation: AssistantConversation) => {
                          const isActive = conversation.$id === activeConversationId
                          return (
                            <div
                              key={conversation.$id}
                              className={cn(
                                'group flex items-center gap-1 rounded-md border border-transparent px-1.5 py-1 transition-colors',
                                isActive
                                  ? 'border-border bg-accent'
                                  : 'hover:border-border hover:bg-accent/60',
                              )}
                            >
                              <button
                                type="button"
                                onClick={() => {
                                  setActiveConversationId(conversation.$id)
                                  setConversationsPopoverOpen(false)
                                }}
                                className="min-w-0 flex-1 text-left"
                              >
                                <p className="truncate text-[12px] font-medium text-foreground">
                                  {conversation.title || 'Untitled conversation'}
                                </p>
                              </button>
                              <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                className="h-6 w-6 shrink-0 opacity-0 transition-opacity group-hover:opacity-100"
                                onClick={(event) => {
                                  event.stopPropagation()
                                  handleDeleteConversation(conversation.$id)
                                }}
                                disabled={deleteConversationMutation.isPending}
                              >
                                <Trash2 className="h-3 w-3" />
                              </Button>
                            </div>
                          )
                        })}
                      </div>
                    )}
                    </div>
                  </PopoverContent>
                </Popover>

                <Button
                  type="button"
                  variant="ghost"
                  className="h-8 w-8 shrink-0 p-0"
                  onClick={handleCreateConversation}
                  disabled={createConversationMutation.isPending}
                >
                  {createConversationMutation.isPending ? (
                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                  ) : (
                    <Plus className="h-3.5 w-3.5" />
                  )}
                </Button>
              </div>
            </div>
            <div className="flex items-center gap-1">
              <button
                onClick={closeChat}
                className="flex h-7 w-7 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
              >
                <X className="h-3.5 w-3.5" />
              </button>
            </div>
          </div>

          <div className="min-h-0 flex-1 overflow-y-auto p-3">
            {messages.length === 0 ? (
              <div className="flex h-full flex-col items-center justify-center">
                <div className="mb-6 flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10">
                  <Lightbulb className="h-8 w-8 text-primary" />
                </div>
                <h3 className="mb-2 text-lg font-semibold text-foreground">
                  How can I help you?
                </h3>
                <p className="mb-6 text-center text-sm text-muted-foreground">
                  Ask about your project, and I can plan and execute actions.
                </p>
                <div className="w-full max-w-md space-y-2">
                  {suggestedQuestions.map((question) => (
                    <button
                      key={question}
                      onClick={() => handleSend(question)}
                      className="w-full rounded-lg border border-border bg-card p-3 text-left text-sm text-foreground transition-colors hover:bg-accent"
                    >
                      {question}
                    </button>
                  ))}
                </div>
              </div>
            ) : (
              <div className="space-y-3">
                {messages.map((message: AssistantMessage) => {
                  const messageText = message.contentText || ''
                  const isUserMessage = message.role.toLowerCase() === 'user'

                  // Avoid duplicate AI placeholders: when the backend created an empty
                  // assistant message during generation, we only show the "Thinking..."
                  // row below (single AI response state).
                  if (!isUserMessage && !messageText.trim()) {
                    return null
                  }

                  return (
                    <AssistantMessageRow
                      key={message.$id}
                      messageId={message.$id}
                      role={message.role}
                      messageText={messageText}
                      deferCodeBlocks={
                        isThinking && message.$id === latestMessageId
                      }
                    />
                  )
                })}
                {isThinking && (
                  <div className="flex">
                    <div className="flex items-center gap-1.5 rounded-md bg-card px-2.5 py-1.5 text-[13px] text-muted-foreground">
                      <Loader2 className="h-3.5 w-3.5 animate-spin" />
                      <span>Thinking...</span>
                    </div>
                  </div>
                )}
                <div ref={messagesEndRef} />
              </div>
            )}
          </div>

          <div className="shrink-0 border-t border-border p-3">
            <div className="flex items-end gap-1.5 rounded-md border border-border bg-card p-1.5">
              <textarea
                ref={inputRef}
                value={input}
                onChange={(e) => setInput(e.target.value)}
                onKeyDown={handleKeyDown}
                placeholder="Ask a question..."
                rows={1}
                className="max-h-32 min-h-[34px] flex-1 resize-none bg-transparent px-1.5 py-1 text-[13px] text-foreground placeholder:text-muted-foreground focus:outline-none"
                style={{
                  height: 'auto',
                  minHeight: '34px',
                }}
                onInput={(e) => {
                  const target = e.target as HTMLTextAreaElement
                  target.style.height = 'auto'
                  target.style.height = `${Math.min(target.scrollHeight, 128)}px`
                }}
              />
              <button
                onClick={() => handleSend()}
                disabled={!input.trim() || createMessageMutation.isPending}
                className={cn(
                  'flex h-8 w-8 shrink-0 items-center justify-center rounded-md transition-colors',
                  input.trim() && !createMessageMutation.isPending
                    ? 'bg-primary text-primary-foreground hover:bg-primary/90'
                    : 'bg-muted text-muted-foreground',
                )}
              >
                <Send className="h-3.5 w-3.5" />
              </button>
            </div>
            <p className="mt-1.5 text-center text-[11px] text-muted-foreground">
              Press Enter to send, Shift+Enter for new line
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}

function makeConversationTitle(text: string): string {
  const cleanText = text.trim().replace(/\s+/g, ' ')
  if (!cleanText) return 'New conversation'
  return cleanText.length > 42 ? `${cleanText.slice(0, 42)}...` : cleanText
}

