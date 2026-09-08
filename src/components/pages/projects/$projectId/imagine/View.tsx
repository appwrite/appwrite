import { useState, useRef, useEffect } from 'react'
import { ArrowUp, Heart, Eye, ExternalLink } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { ImagineIcon } from './Icon'

// Richer prompts for each suggestion
const suggestionPrompts: Record<string, string> = {
  'Financial dashboard':
    'Build a financial dashboard with real-time portfolio tracking, expense categorization, interactive charts showing spending trends, and a clean dark mode interface',
  'Fitness tracker':
    'Create a fitness tracking app with workout logging, progress charts, calorie tracking, exercise library with animations, and weekly goal setting',
  'SaaS landing page':
    'Design a modern SaaS landing page with hero section, feature highlights, pricing tiers, testimonials carousel, and a sleek gradient aesthetic',
}

const suggestionChips = Object.keys(suggestionPrompts)

// Community project examples
const communityProjects = [
  {
    id: '507f1f77bcf86cd799439500',
    title: 'Recipe manager',
    description: 'Organize and discover recipes with AI suggestions',
    author: 'Paige Dineen',
    likes: 234,
    views: 1.2,
    image:
      'https://images.unsplash.com/photo-1466637574441-749b8f19452f?w=400&h=300&fit=crop',
  },
  {
    id: '507f1f77bcf86cd799439501',
    title: 'Task flow',
    description: 'Kanban-style project management with automations',
    author: 'Cabe Gallo',
    likes: 189,
    views: 0.9,
    image:
      'https://images.unsplash.com/photo-1611224923853-80b023f02d71?w=400&h=300&fit=crop',
  },
  {
    id: '507f1f77bcf86cd799439502',
    title: 'Budget buddy',
    description: 'Personal finance tracker with spending insights',
    author: 'Happy Quinn',
    likes: 312,
    views: 1.8,
    image:
      'https://images.unsplash.com/photo-1554224155-6726b3ff858f?w=400&h=300&fit=crop',
  },
  {
    id: '507f1f77bcf86cd799439503',
    title: 'Study notes',
    description: 'AI-powered note taking with flashcard generation',
    author: 'Toby Curtis',
    likes: 156,
    views: 0.7,
    image:
      'https://images.unsplash.com/photo-1456324504439-367cee3b3c32?w=400&h=300&fit=crop',
  },
]

export function View() {
  const t = useT()
  const [prompt, setPrompt] = useState('')
  const [isTyping, setIsTyping] = useState(false)
  const textareaRef = useRef<HTMLTextAreaElement>(null)
  const typingRef = useRef<NodeJS.Timeout | null>(null)

  // Cleanup typing animation on unmount
  useEffect(() => {
    return () => {
      if (typingRef.current) {
        clearTimeout(typingRef.current)
      }
    }
  }, [])

  const handleSubmit = () => {
    if (!prompt.trim() || isTyping) return
    // TODO: Submit prompt
  }

  const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault()
      handleSubmit()
    }
  }

  const typeText = (text: string) => {
    // Clear any existing typing animation
    if (typingRef.current) {
      clearTimeout(typingRef.current)
    }

    setIsTyping(true)
    setPrompt('')
    textareaRef.current?.focus()

    let index = 0
    const typeChar = () => {
      if (index < text.length) {
        setPrompt(text.slice(0, index + 1))
        index++
        // Vary typing speed slightly for natural feel
        const delay = 15 + Math.random() * 25
        typingRef.current = setTimeout(typeChar, delay)
      } else {
        setIsTyping(false)
      }
    }

    typeChar()
  }

  const handleChipClick = (chip: string) => {
    if (isTyping) return
    const fullPrompt = suggestionPrompts[chip]
    if (fullPrompt) {
      typeText(fullPrompt)
    }
  }

  const formatViews = (views: number) => {
    return views >= 1 ? `${views.toFixed(1)}k` : `${(views * 1000).toFixed(0)}`
  }

  return (
    <div className="flex flex-col px-4 pb-8 pt-6">
      <div className="mx-auto mt-12 w-full max-w-2xl">
        {/* Icon */}
        <div className="mb-4 flex justify-center">
          <ImagineIcon size={64} className="text-foreground" />
        </div>

        {/* Heading */}
        <h1 className="mb-2 text-center text-xl font-semibold tracking-tight text-foreground sm:text-2xl">
          {t('Build something real')}
        </h1>

        {/* Description */}
        <p className="mx-auto mb-5 max-w-md text-center text-[13px] text-muted-foreground">
          {t(
            'Turn your ideas into functional products with the most complete AI builder ever made.',
          )}
        </p>

        {/* Prompt Input Card - More compact */}
        <div className="rounded-xl border border-border bg-card/50 p-3">
          <div className="relative">
            <textarea
              ref={textareaRef}
              value={prompt}
              onChange={(e) => !isTyping && setPrompt(e.target.value)}
              onKeyDown={handleKeyDown}
              placeholder={t('Describe what you want to build...')}
              className="min-h-[80px] w-full resize-none bg-transparent text-[13px] text-foreground placeholder:text-muted-foreground/60 focus:outline-none"
              rows={3}
            />

            {/* Submit Button */}
            <Button
              onClick={handleSubmit}
              disabled={!prompt.trim() || isTyping}
              size="icon"
              className={cn(
                'absolute bottom-0 end-0 h-8 w-8 rounded-lg transition-all',
                prompt.trim() && !isTyping
                  ? 'bg-foreground text-background hover:bg-foreground/90'
                  : 'bg-muted text-muted-foreground',
              )}
              aria-label={t('Send message')}
            >
              <ArrowUp className="h-3.5 w-3.5" />
            </Button>
          </div>

          {/* Suggestion Chips */}
          <div className="mt-3 flex flex-wrap gap-1.5">
            {suggestionChips.map((chip) => (
              <button
                key={chip}
                onClick={() => handleChipClick(chip)}
                disabled={isTyping}
                className={cn(
                  'rounded-full border border-border bg-background/50 px-2.5 py-1 text-[11px] text-muted-foreground transition-colors',
                  isTyping
                    ? 'cursor-not-allowed opacity-50'
                    : 'hover:bg-accent hover:text-foreground',
                )}
              >
                {t(chip)}
              </button>
            ))}
          </div>
        </div>

        {/* CTA Buttons */}
        <div className="mt-6 flex flex-wrap items-center justify-center gap-3">
          <a
            href="https://imagine.dev"
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-2 rounded-lg bg-foreground px-4 py-2 text-[13px] font-medium text-background transition-colors hover:bg-foreground/90"
          >
            {t('Visit Imagine.dev')}
            <ExternalLink className="h-3.5 w-3.5" />
          </a>
          <a
            href="https://imagine.dev/sign-in"
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-2 rounded-lg border border-border bg-card/50 px-4 py-2 text-[13px] font-medium text-foreground transition-colors hover:bg-accent"
          >
            {t('Sign in')}
            <ExternalLink className="h-3.5 w-3.5" />
          </a>
          <a
            href="https://imagine.dev/sign-up"
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-2 rounded-lg border border-border bg-card/50 px-4 py-2 text-[13px] font-medium text-foreground transition-colors hover:bg-accent"
          >
            {t('Sign up')}
            <ExternalLink className="h-3.5 w-3.5" />
          </a>
        </div>
      </div>

      {/* Community Projects Section */}
      <div className="mx-auto mt-10 w-full max-w-4xl">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-sm font-medium text-foreground">
            {t('Community projects')}
          </h2>
          <button className="text-[12px] text-muted-foreground transition-colors hover:text-foreground">
            {t('View all')}
          </button>
        </div>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {communityProjects.map((project) => (
            <div
              key={project.id}
              className="group cursor-pointer overflow-hidden rounded-lg border border-border bg-card/50 transition-all hover:border-foreground/20 hover:bg-card"
            >
              {/* Project Image */}
              <div className="relative h-28 overflow-hidden">
                <img
                  src={project.image}
                  alt={project.title}
                  className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                />
                <div className="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent" />
              </div>

              {/* Project Info */}
              <div className="p-2.5">
                <h3 className="text-[13px] font-medium text-foreground">
                  {t(project.title)}
                </h3>
                <p className="mt-0.5 line-clamp-1 text-[11px] text-muted-foreground">
                  {t(project.description)}
                </p>

                <div className="mt-2 flex items-center justify-between">
                  <span className="text-[10px] text-muted-foreground">
                    {t('by')} {project.author}
                  </span>
                  <div className="flex items-center gap-2 text-[10px] text-muted-foreground">
                    <span className="flex items-center gap-0.5">
                      <Heart className="h-3 w-3" />
                      {project.likes}
                    </span>
                    <span className="flex items-center gap-0.5">
                      <Eye className="h-3 w-3" />
                      {formatViews(project.views)}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}
