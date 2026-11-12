import { Plus } from 'lucide-react'
import type { ReactNode } from 'react'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  AiTileSoftLight,
  type AiTileSoftLightTone,
} from '@/components/pages/home/HomeSoftLights'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

export const AI_SKILL_TAGS = [
  'createDocument',
  'uploadFile',
  'getUser',
  'listFiles',
  'deleteSession',
  'getAccount',
  'listTeams',
] as const

export type AiFeatureTitleBadge = {
  label: string
  variant: 'info' | 'inactive'
}

export function AiFeatureCard({
  title,
  description,
  cta,
  shade,
  titleBadge,
  className,
  children,
}: {
  title: string
  description: string
  cta: ReactNode
  shade?: AiTileSoftLightTone
  titleBadge?: AiFeatureTitleBadge
  className?: string
  children: ReactNode
}) {
  return (
    <article
      className={cn('relative flex min-h-[22rem] flex-col overflow-hidden', className)}
    >
      {shade ? <AiTileSoftLight tone={shade} /> : null}

      <div className="relative flex flex-1 flex-col p-5 sm:p-6">
        <div className="space-y-2">
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="font-aeonik-pro text-[16px] font-normal text-foreground">
              {title}
            </h3>
            {titleBadge ? (
              <Badge variant={titleBadge.variant} className="text-[10px]">
                {titleBadge.label}
              </Badge>
            ) : null}
          </div>
          <p className="text-[13px] leading-5 text-muted-foreground">{description}</p>
        </div>

        <div className="mt-4 flex flex-1 flex-col">{children}</div>

        <div className="mt-4">{cta}</div>
      </div>
    </article>
  )
}

export function AiMockTypingInput({
  placeholder,
  typedText,
  typeDelayMs = 150,
  leadingIcon,
}: {
  placeholder: string
  typedText: string
  typeDelayMs?: number
  leadingIcon?: ReactNode
}) {
  const t = useT()
  const translatedTypedText = t(typedText)
  const cursorDelay = typeDelayMs + translatedTypedText.length * 55

  return (
    <div className="flex items-center gap-2 rounded-md border border-border bg-background/80 px-3 py-2 text-[10px] sm:text-[11px]">
      {leadingIcon}
      <div className="relative min-w-0 flex-1">
        <span className="text-muted-foreground transition-opacity duration-200 group-hover/visual:opacity-0 motion-reduce:group-hover/visual:opacity-100">
          {t(placeholder)}
        </span>
        <span className="absolute inset-0 flex items-center opacity-0 group-hover/visual:opacity-100 motion-reduce:opacity-100">
          <span className="inline-flex max-w-full items-center overflow-hidden whitespace-nowrap text-muted-foreground">
            <span
              className="inline-block max-w-0 overflow-hidden whitespace-nowrap group-hover/visual:animate-[ai-mock-type-reveal_1.7s_steps(24,end)_forwards] motion-reduce:max-w-none motion-reduce:group-hover/visual:animate-none"
              style={{ animationDelay: `${typeDelayMs}ms` }}
            >
              {translatedTypedText}
            </span>
            <span
              className="ms-px inline-block h-3 w-px shrink-0 bg-muted-foreground opacity-0 group-hover/visual:animate-[ai-mock-cursor-blink_1s_step-end_infinite] motion-reduce:opacity-100 motion-reduce:group-hover/visual:animate-none"
              style={{ animationDelay: `${cursorDelay}ms` }}
            />
          </span>
        </span>
      </div>
    </div>
  )
}

export function AiMcpMockVisual() {
  const t = useT()
  return (
    <div className="group/visual relative min-h-[14rem] flex-1 overflow-hidden rounded-lg border border-border bg-muted/25">
      <div className="absolute inset-y-0 start-0 w-[38%] border-e border-border/80 bg-background/40 p-3">
        <div className="space-y-2">
          {Array.from({ length: 5 }).map((_, index) => (
            <div
              key={index}
              className="h-2 rounded-full bg-muted-foreground/15 group-hover/visual:animate-[ai-mock-sidebar-pulse_1.4s_ease-in-out_infinite] motion-reduce:group-hover/visual:animate-none"
              style={{
                width: `${68 - index * 8}%`,
                animationDelay: `${index * 120}ms`,
              }}
            />
          ))}
        </div>
      </div>
      <div className="absolute inset-y-0 end-0 flex w-[62%] flex-col justify-end p-3 sm:p-4">
        <div className="mb-auto space-y-2 pt-1">
          <div
            className="ms-auto max-w-[92%] rounded-lg border border-border bg-background/90 px-2.5 py-2 text-[10px] leading-snug text-muted-foreground opacity-90 group-hover/visual:animate-[ai-mock-fade-in_0.45s_ease-out] motion-reduce:group-hover/visual:animate-none sm:text-[11px]"
            style={{ animationDelay: '80ms' }}
          >
            {t('Create a collection for user profiles')}
          </div>
          <div
            className="max-w-[92%] rounded-lg border border-border bg-background/90 px-2.5 py-2 text-[10px] leading-snug text-muted-foreground group-hover/visual:animate-[ai-mock-fade-in_0.55s_ease-out] motion-reduce:group-hover/visual:animate-none sm:text-[11px]"
            style={{ animationDelay: '380ms' }}
          >
            <span className="inline-flex items-center gap-1.5 group-hover/visual:hidden motion-reduce:hidden">
              <span
                className="size-1 rounded-full bg-muted-foreground/40 animate-[ai-mock-thinking-dots_1s_ease-in-out_infinite]"
                style={{ animationDelay: '0ms' }}
              />
              <span
                className="size-1 rounded-full bg-muted-foreground/40 animate-[ai-mock-thinking-dots_1s_ease-in-out_infinite]"
                style={{ animationDelay: '150ms' }}
              />
              <span
                className="size-1 rounded-full bg-muted-foreground/40 animate-[ai-mock-thinking-dots_1s_ease-in-out_infinite]"
                style={{ animationDelay: '300ms' }}
              />
            </span>
            <span className="hidden group-hover/visual:inline motion-reduce:inline">
              {t('Setting up collection with email and name attributes.')}
            </span>
            <span className="group-hover/visual:hidden motion-reduce:hidden">
              {t('Thinking...')}
            </span>
          </div>
        </div>
        <AiMockTypingInput
          placeholder="Ask anything..."
          typedText="Add avatar field to profiles"
          typeDelayMs={220}
        />
      </div>
    </div>
  )
}

export function AiSkillsMockVisual() {
  return (
    <div className="group/visual relative min-h-[14rem] flex-1 overflow-hidden rounded-lg border border-border bg-muted/25 p-4 sm:p-5">
      <AiMockTypingInput
        placeholder="Ask anything..."
        typedText="List files in my bucket"
        typeDelayMs={120}
        leadingIcon={
          <Plus
            className="size-3.5 shrink-0 text-muted-foreground transition-transform duration-200 group-hover/visual:rotate-90 motion-reduce:group-hover/visual:rotate-0"
            aria-hidden
          />
        }
      />
      <div className="mt-4 flex flex-wrap gap-2">
        {AI_SKILL_TAGS.map((tag, index) => (
          <span
            key={tag}
            className="rounded-md border border-border bg-background/80 px-2 py-1 font-mono text-[10px] text-muted-foreground group-hover/visual:animate-[ai-mock-tag-press_0.4s_ease-out_both] motion-reduce:group-hover/visual:animate-none sm:text-[11px]"
            style={{ animationDelay: `${280 + index * 110}ms` }}
          >
            {tag}
          </span>
        ))}
      </div>
    </div>
  )
}

export function AiPromptsMockVisual() {
  const t = useT()
  const prompts = [
    'Scaffold auth for React',
    'Add file upload to my app',
    'Create a teams table',
  ] as const

  return (
    <div className="group/visual relative min-h-[14rem] flex-1 overflow-hidden rounded-lg border border-border bg-muted/25 p-4 sm:p-5">
      <div className="space-y-2">
        {prompts.map((prompt, index) => (
          <div
            key={prompt}
            className="rounded-md border border-border bg-background/80 px-3 py-2 text-[10px] text-muted-foreground opacity-70 transition-opacity group-hover/visual:opacity-100 group-hover/visual:animate-[ai-mock-fade-in_0.45s_ease-out] motion-reduce:group-hover/visual:animate-none motion-reduce:opacity-100 sm:text-[11px]"
            style={{ animationDelay: `${120 + index * 160}ms` }}
          >
            {t(prompt)}
          </div>
        ))}
      </div>
      <div className="mt-4">
        <AiMockTypingInput
          placeholder="Describe a feature..."
          typedText="Build messaging with email and push"
          typeDelayMs={180}
        />
      </div>
    </div>
  )
}

export function AiFeatureCtaButton({
  href,
  label,
}: {
  href: string
  label: string
  external?: boolean
}) {
  return (
    <Button variant="outline" className="h-9 text-[13px]" asChild>
      <MarketingSiteLink href={href}>{label}</MarketingSiteLink>
    </Button>
  )
}
