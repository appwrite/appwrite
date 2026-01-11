import { useState } from 'react'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  MessageSquarePlus,
  ThumbsUp,
  ThumbsDown,
  Lightbulb,
  Bug,
  Send,
  Check,
} from 'lucide-react'
import { cn } from '@/lib/utils'

type FeedbackType = 'general' | 'bug' | 'idea' | 'like' | 'dislike'

interface FeedbackOption {
  type: FeedbackType
  icon: React.ReactNode
  label: string
  placeholder: string
}

const feedbackOptions: FeedbackOption[] = [
  {
    type: 'like',
    icon: <ThumbsUp className="h-4 w-4" />,
    label: 'I like something',
    placeholder: "What's working well for you?",
  },
  {
    type: 'dislike',
    icon: <ThumbsDown className="h-4 w-4" />,
    label: "I don't like something",
    placeholder: 'What could be improved?',
  },
  {
    type: 'idea',
    icon: <Lightbulb className="h-4 w-4" />,
    label: 'I have an idea',
    placeholder: 'Share your idea with us...',
  },
  {
    type: 'bug',
    icon: <Bug className="h-4 w-4" />,
    label: 'I found a bug',
    placeholder: 'Describe the issue you encountered...',
  },
]

export function FeedbackPopover() {
  const [isOpen, setIsOpen] = useState(false)
  const [selectedType, setSelectedType] = useState<FeedbackType | null>(null)
  const [message, setMessage] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [isSubmitted, setIsSubmitted] = useState(false)

  const selectedOption = feedbackOptions.find((o) => o.type === selectedType)

  const handleSubmit = async () => {
    if (!selectedType || !message.trim()) return

    setIsSubmitting(true)

    // Simulate submission delay
    await new Promise((resolve) => setTimeout(resolve, 800))

    // Here you would typically send the feedback to your backend
    console.log('Feedback submitted:', { type: selectedType, message })

    setIsSubmitting(false)
    setIsSubmitted(true)

    // Reset after showing success
    setTimeout(() => {
      setIsOpen(false)
      setSelectedType(null)
      setMessage('')
      setIsSubmitted(false)
    }, 1500)
  }

  const handleBack = () => {
    setSelectedType(null)
    setMessage('')
  }

  const handleOpenChange = (open: boolean) => {
    setIsOpen(open)
    if (!open) {
      // Reset state when closing
      setTimeout(() => {
        setSelectedType(null)
        setMessage('')
        setIsSubmitted(false)
      }, 200)
    }
  }

  return (
    <Popover open={isOpen} onOpenChange={handleOpenChange}>
      <Tooltip>
        <TooltipTrigger asChild>
          <PopoverTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8 text-muted-foreground hover:bg-accent hover:text-foreground"
            >
              <MessageSquarePlus className="h-4 w-4" />
              <span className="sr-only">Feedback</span>
            </Button>
          </PopoverTrigger>
        </TooltipTrigger>
        <TooltipContent>
          <p>Feedback</p>
        </TooltipContent>
      </Tooltip>
      <PopoverContent align="end" className="w-80 p-0">
        {isSubmitted ? (
          // Success state
          <div className="flex flex-col items-center justify-center gap-3 p-8">
            <div className="flex h-12 w-12 items-center justify-center rounded-full bg-green-500/10">
              <Check className="h-6 w-6 text-green-500" />
            </div>
            <div className="text-center">
              <p className="font-medium">Thank you!</p>
              <p className="text-sm text-muted-foreground">
                Your feedback helps us improve.
              </p>
            </div>
          </div>
        ) : selectedType ? (
          // Feedback form
          <div className="p-4">
            <div className="mb-4 flex items-center gap-2">
              <button
                onClick={handleBack}
                className="text-sm text-muted-foreground hover:text-foreground"
              >
                ← Back
              </button>
            </div>
            <div className="mb-3 flex items-center gap-2">
              <div className="flex h-8 w-8 items-center justify-center rounded-md bg-primary/10 text-primary">
                {selectedOption?.icon}
              </div>
              <span className="font-medium">{selectedOption?.label}</span>
            </div>
            <Textarea
              placeholder={selectedOption?.placeholder}
              value={message}
              onChange={(e) => setMessage(e.target.value)}
              className="mb-3 min-h-[100px] resize-none"
              autoFocus
            />
            <div className="flex items-center justify-between">
              <p className="text-xs text-muted-foreground">
                {message.length}/500
              </p>
              <Button
                size="sm"
                onClick={handleSubmit}
                disabled={
                  !message.trim() || message.length > 500 || isSubmitting
                }
                className="gap-2"
              >
                <Send className="h-3.5 w-3.5" />
                Send Feedback
              </Button>
            </div>
          </div>
        ) : (
          // Feedback type selection
          <div className="p-4">
            <div className="mb-3">
              <h4 className="font-medium">Send Feedback</h4>
              <p className="text-sm text-muted-foreground">
                Help us improve your experience
              </p>
            </div>
            <div className="grid grid-cols-2 gap-2">
              {feedbackOptions.map((option) => (
                <button
                  key={option.type}
                  onClick={() => setSelectedType(option.type)}
                  className={cn(
                    'flex flex-col items-center gap-2 rounded-lg border p-3 text-center transition-colors',
                    'hover:border-primary/50 hover:bg-accent',
                  )}
                >
                  <div
                    className={cn(
                      'flex h-8 w-8 items-center justify-center rounded-md',
                      option.type === 'like' &&
                        'bg-green-500/10 text-green-500',
                      option.type === 'dislike' &&
                        'bg-orange-500/10 text-orange-500',
                      option.type === 'idea' &&
                        'bg-yellow-500/10 text-yellow-500',
                      option.type === 'bug' && 'bg-red-500/10 text-red-500',
                    )}
                  >
                    {option.icon}
                  </div>
                  <span className="text-xs font-medium">{option.label}</span>
                </button>
              ))}
            </div>
          </div>
        )}
      </PopoverContent>
    </Popover>
  )
}
