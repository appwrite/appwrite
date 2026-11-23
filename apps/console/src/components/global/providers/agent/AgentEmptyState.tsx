import type { ReactNode, RefObject } from 'react'
import { Link } from '@tanstack/react-router'
import {
  ThinkingBubble,
  type SphereColorMode,
  type SphereShapeMode,
} from '@/components/global/shared/ThinkingBubble'
import { McpIcon } from '@/components/global/shared/McpIcon'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'

type AgentEmptyStateProps = {
  hasActiveMcp: boolean
  suggestions: string[]
  onSelectSuggestion: (question: string) => void
  sphereSize: number
  activityRef: RefObject<number>
  colorMode: SphereColorMode
  shapeMode: SphereShapeMode
  particleCount?: number
  debugSlot?: ReactNode
  /** When true, hide suggestions and show a sign-in CTA instead. */
  requireSignIn?: boolean
}

export function AgentEmptyState({
  hasActiveMcp,
  suggestions,
  onSelectSuggestion,
  sphereSize,
  activityRef,
  colorMode,
  shapeMode,
  particleCount,
  debugSlot,
  requireSignIn = false,
}: AgentEmptyStateProps) {
  const t = useT()

  return (
    <div className="flex min-h-full flex-1 flex-col items-center justify-center py-4">
      <div className="mx-auto flex w-full max-w-md flex-col">
        <div className="flex flex-col items-center">
          <div className="mb-4 scale-[0.82]">
            <ThinkingBubble
              size={sphereSize}
              activityRef={activityRef}
              colorMode={colorMode}
              shapeMode={shapeMode}
              particleCount={particleCount}
            />
          </div>
          <h3 className="text-center text-lg font-semibold text-foreground">
            {requireSignIn
              ? t('Sign in to use the agent')
              : hasActiveMcp
                ? t('What should we do next?')
                : t('How can I help you?')}
          </h3>
          {requireSignIn ? (
            <p className="mt-2 max-w-sm text-center text-[13px] text-muted-foreground">
              {t(
                'Create an account or sign in to chat with the Appwrite Agent about your projects.',
              )}
            </p>
          ) : hasActiveMcp ? (
            <div className="mt-2 inline-flex items-center gap-1.5 text-[12px] text-muted-foreground">
              <span className="relative flex h-1.5 w-1.5">
                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-500/40" />
                <span className="relative inline-flex h-1.5 w-1.5 rounded-full bg-emerald-500" />
              </span>
              <McpIcon className="h-3 w-3" />
              <span>{t('MCP ready')}</span>
            </div>
          ) : (
            <p className="mt-2 max-w-sm text-center text-[13px] text-muted-foreground">
              {t(
                'I can inspect your project, explain issues, suggest next steps, and run approved actions.',
              )}
            </p>
          )}
        </div>

        {requireSignIn ? (
          <div className="mt-6 flex flex-col items-center gap-2">
            <Button asChild className="h-9 min-w-[160px] px-4 text-[13px]">
              <Link
                to="/sign-in"
                search={{ redirect: '/agent' }}
                {...analyticsAttrs('auth-sign-in')}
              >
                {t('Sign in')}
              </Link>
            </Button>
            <Button
              asChild
              variant="ghost"
              className="h-8 text-[12px] text-muted-foreground"
            >
              <Link
                to="/sign-up"
                search={{ redirect: '/' }}
                {...analyticsAttrs('auth-sign-up')}
              >
                {t('Create an account')}
              </Link>
            </Button>
          </div>
        ) : (
          <div className="mt-6 space-y-2.5">
            {suggestions.map((question) => (
              <button
                key={question}
                type="button"
                onClick={() => onSelectSuggestion(question)}
                className="w-full rounded-lg border border-transparent bg-muted/35 px-3.5 py-3 text-start text-sm leading-relaxed text-foreground transition-colors hover:border-border hover:bg-muted/55"
                {...analyticsAttrs('agent-suggestion')}
              >
                {t(question)}
              </button>
            ))}
          </div>
        )}
      </div>
      {debugSlot ? (
        <div className="mx-auto mt-6 w-full max-w-md">{debugSlot}</div>
      ) : null}
    </div>
  )
}
