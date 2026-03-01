import { createContext, useContext, useState, useCallback } from 'react'
import { useRef, useEffect } from 'react'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  X,
  Send,
  Sparkles,
  Bot,
  User,
  Loader2,
  GripVertical,
} from 'lucide-react'
import { cn } from '@/lib/utils'

interface Message {
  id: string
  role: 'user' | 'assistant'
  content: string
  timestamp: Date
}

interface AIChatContextValue {
  isOpen: boolean
  messages: Message[]
  isLoading: boolean
  openChat: () => void
  closeChat: () => void
  toggleChat: () => void
  addMessage: (message: Message) => void
  setMessages: React.Dispatch<React.SetStateAction<Message[]>>
  setIsLoading: (loading: boolean) => void
}

const AIChatContext = createContext<AIChatContextValue | null>(null)

export function AIChatProvider({ children }: { children: React.ReactNode }) {
  const [isOpen, setIsOpen] = useState(false)
  const [messages, setMessages] = useState<Message[]>([])
  const [isLoading, setIsLoading] = useState(false)

  const openChat = useCallback(() => setIsOpen(true), [])
  const closeChat = useCallback(() => setIsOpen(false), [])
  const toggleChat = useCallback(() => setIsOpen((prev) => !prev), [])
  const addMessage = useCallback((message: Message) => {
    setMessages((prev) => [...prev, message])
  }, [])

  return (
    <AIChatContext.Provider
      value={{
        isOpen,
        messages,
        isLoading,
        openChat,
        closeChat,
        toggleChat,
        addMessage,
        setMessages,
        setIsLoading,
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
      messages: [],
      isLoading: false,
      openChat: () => {},
      closeChat: () => {},
      toggleChat: () => {},
      addMessage: () => {},
      setMessages: () => {},
      setIsLoading: () => {},
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

export function AIChatPanel() {
  const { features } = useConsoleProfile()
  const { isOpen, closeChat, messages, isLoading, setMessages, setIsLoading } =
    useAIChat()
  const [input, setInput] = useState('')

  if (!features.aiAssistant) return null
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

  const handleSend = async (content: string = input) => {
    if (!content.trim() || isLoading) return

    const userMessage = {
      id: crypto.randomUUID(),
      role: 'user' as const,
      content: content.trim(),
      timestamp: new Date(),
    }

    setMessages((prev) => [...prev, userMessage])
    setInput('')
    setIsLoading(true)

    // Simulate AI response (replace with actual API call)
    setTimeout(
      () => {
        const assistantMessage = {
          id: crypto.randomUUID(),
          role: 'assistant' as const,
          content: getSimulatedResponse(content),
          timestamp: new Date(),
        }
        setMessages((prev) => [...prev, assistantMessage])
        setIsLoading(false)
      },
      1000 + Math.random() * 1000,
    )
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

      {/* Header */}
      <div className="flex h-14 shrink-0 items-center justify-between border-b border-border px-4">
        <div className="flex items-center gap-2">
          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10">
            <Sparkles className="h-4 w-4 text-primary" />
          </div>
          <h2 className="text-sm font-semibold text-foreground">Assistant</h2>
        </div>
        <button
          onClick={closeChat}
          className="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
        >
          <X className="h-4 w-4" />
        </button>
      </div>

      {/* Messages */}
      <div className="flex-1 overflow-y-auto p-4">
        {messages.length === 0 ? (
          <div className="flex h-full flex-col items-center justify-center">
            <div className="mb-6 flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10">
              <Sparkles className="h-8 w-8 text-primary" />
            </div>
            <h3 className="mb-2 text-lg font-semibold text-foreground">
              How can I help you?
            </h3>
            <p className="mb-6 text-center text-sm text-muted-foreground">
              Ask me anything about Appwrite, your project, or how to build your
              app.
            </p>
            <div className="w-full space-y-2">
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
          <div className="space-y-4">
            {messages.map((message) => (
              <div
                key={message.id}
                className={cn(
                  'flex gap-3',
                  message.role === 'user' && 'flex-row-reverse',
                )}
              >
                <div
                  className={cn(
                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg',
                    message.role === 'assistant'
                      ? 'bg-primary/10'
                      : 'bg-accent',
                  )}
                >
                  {message.role === 'assistant' ? (
                    <Bot className="h-4 w-4 text-primary" />
                  ) : (
                    <User className="h-4 w-4 text-muted-foreground" />
                  )}
                </div>
                <div
                  className={cn(
                    'max-w-[85%] rounded-lg px-3 py-2 text-sm whitespace-pre-wrap',
                    message.role === 'assistant'
                      ? 'bg-card text-foreground'
                      : 'bg-primary text-primary-foreground',
                  )}
                >
                  {message.content}
                </div>
              </div>
            ))}
            {isLoading && (
              <div className="flex gap-3">
                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                  <Bot className="h-4 w-4 text-primary" />
                </div>
                <div className="flex items-center gap-2 rounded-lg bg-card px-3 py-2 text-sm text-muted-foreground">
                  <Loader2 className="h-4 w-4 animate-spin" />
                  <span>Thinking...</span>
                </div>
              </div>
            )}
            <div ref={messagesEndRef} />
          </div>
        )}
      </div>

      {/* Input */}
      <div className="shrink-0 border-t border-border p-4">
        <div className="flex items-end gap-2 rounded-lg border border-border bg-card p-2">
          <textarea
            ref={inputRef}
            value={input}
            onChange={(e) => setInput(e.target.value)}
            onKeyDown={handleKeyDown}
            placeholder="Ask a question..."
            rows={1}
            className="max-h-32 min-h-[36px] flex-1 resize-none bg-transparent px-2 py-1.5 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none"
            style={{
              height: 'auto',
              minHeight: '36px',
            }}
            onInput={(e) => {
              const target = e.target as HTMLTextAreaElement
              target.style.height = 'auto'
              target.style.height = `${Math.min(target.scrollHeight, 128)}px`
            }}
          />
          <button
            onClick={() => handleSend()}
            disabled={!input.trim() || isLoading}
            className={cn(
              'flex h-9 w-9 shrink-0 items-center justify-center rounded-md transition-colors',
              input.trim() && !isLoading
                ? 'bg-primary text-primary-foreground hover:bg-primary/90'
                : 'bg-muted text-muted-foreground',
            )}
          >
            <Send className="h-4 w-4" />
          </button>
        </div>
        <p className="mt-2 text-center text-xs text-muted-foreground">
          Press Enter to send, Shift+Enter for new line
        </p>
      </div>
    </div>
  )
}

// Simulated responses for demo purposes
function getSimulatedResponse(question: string): string {
  const lowerQuestion = question.toLowerCase()

  if (lowerQuestion.includes('database')) {
    return "To create a new database in Appwrite:\n\n1. Go to the Databases section in your project\n2. Click 'Create database'\n3. Enter a name and optional ID\n4. Start adding collections to organize your data\n\nNeed help with anything specific about databases?"
  }

  if (
    lowerQuestion.includes('authentication') ||
    lowerQuestion.includes('auth')
  ) {
    return 'Setting up authentication in Appwrite is straightforward:\n\n1. Navigate to the Auth section\n2. Enable the authentication methods you need (Email/Password, OAuth, etc.)\n3. Configure your security settings\n4. Use the Appwrite SDK to implement sign-up and sign-in in your app\n\nWould you like more details on a specific auth method?'
  }

  if (
    lowerQuestion.includes('storage') ||
    lowerQuestion.includes('upload') ||
    lowerQuestion.includes('file')
  ) {
    return "To upload files to Appwrite Storage:\n\n1. Go to the Storage section\n2. Create a bucket with your desired permissions\n3. Use the SDK's storage.createFile() method to upload\n4. Set appropriate file permissions for access control\n\nWant me to show you a code example?"
  }

  if (lowerQuestion.includes('function') || lowerQuestion.includes('deploy')) {
    return "To deploy a function in Appwrite:\n\n1. Go to the Functions section\n2. Click 'Create function'\n3. Choose your runtime (Node.js, Python, etc.)\n4. Write your function code or upload a deployment\n5. Configure triggers (HTTP, schedule, events)\n6. Deploy and test!\n\nNeed help with function triggers or execution?"
  }

  return "I'm here to help you with Appwrite! I can assist with:\n\n• Database setup and queries\n• Authentication configuration\n• File storage and management\n• Serverless functions\n• Real-time subscriptions\n• And much more!\n\nWhat would you like to know?"
}
