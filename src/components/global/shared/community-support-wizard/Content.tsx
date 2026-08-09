'use client'

import type { ReactNode } from 'react'
import {
  COMMUNITY_SUPPORT_ACTIONS,
  type CommunitySupportActionId,
} from '@/lib/community/support-prompt'
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { ExternalLink } from 'lucide-react'
import { ActionLink } from './ActionLink'
import { ShareOnXCard } from './ShareOnXCard'

export type CommunitySupportWizardContentProps = {
  onSkip: () => void
  onAction: (actionId: CommunitySupportActionId) => void
}

function SkipFooter({ onSkip }: { onSkip: () => void }) {
  const t = useT()
  return (
    <Button variant="outline" onClick={onSkip} data-analytics-track="manual">
      {t('Skip for now')}
    </Button>
  )
}

function Shell({
  onSkip,
  children,
}: {
  onSkip: () => void
  children: ReactNode
}) {
  const t = useT()
  return (
    <WizardLayout
      title={<AppwriteLogo className="h-5 w-auto shrink-0" />}
      fullscreen
      useSidebar={false}
      constrainWidth
      maxWidth="max-w-4xl"
      footerAlign="right"
      skipInitialFieldFocus
      onClose={onSkip}
      footer={<SkipFooter onSkip={onSkip} />}
    >
      <div className="space-y-3" data-analytics-track="manual">
        <h2 className="text-[17px] font-semibold tracking-tight text-foreground">
          {t('A note from the team')}
        </h2>
        {children}
      </div>
    </WizardLayout>
  )
}

function TeamNote() {
  const t = useT()
  return (
    <div className="space-y-4 border-b border-border pb-8 text-[14px] leading-relaxed text-foreground/90">
      <p>{t('Hey,')}</p>
      <p>
        {t(
          'Sorry to interrupt. We know you came here to build, not to read a message from us.',
        )}
      </p>
      <p>
        {t(
          'We are a product-obsessed team. Our job is to make Appwrite something you love building on. The part we cannot do alone is spreading the word and welcoming the next wave of developers.',
        )}
      </p>
      <p>
        {t(
          'If you have a minute, here is how you can help. If not, skip and get back to work.',
        )}
      </p>
    </div>
  )
}

function ActionCard({
  actionId,
  onAction,
  className,
  titleClassName,
}: {
  actionId: Exclude<CommunitySupportActionId, 'share'>
  onAction: (actionId: CommunitySupportActionId) => void
  className?: string
  titleClassName?: string
}) {
  const t = useT()
  const action = COMMUNITY_SUPPORT_ACTIONS.find((item) => item.id === actionId)
  if (!action) return null
  const Icon = action.icon

  return (
    <ActionLink
      action={action}
      onAction={onAction}
      className={cn(
        'flex h-full w-full flex-col justify-between gap-4 rounded-2xl border border-border bg-card/50 p-5 text-start transition-colors hover:bg-muted/40 min-h-[160px]',
        className,
      )}
    >
      <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
        <Icon className="h-4 w-4" />
      </div>
      <div className="min-w-0 space-y-1.5">
        <div className="flex items-center gap-1.5">
          <p className={cn('text-[14px] font-semibold', titleClassName)}>
            {t(action.title)}
          </p>
          {action.external ? (
            <ExternalLink className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
          ) : null}
        </div>
        <p className="text-[12px] text-muted-foreground leading-snug">
          {t(action.description)}
        </p>
      </div>
    </ActionLink>
  )
}

export function CommunitySupportWizardContent({
  onSkip,
  onAction,
}: CommunitySupportWizardContentProps) {
  const { features } = useConsoleProfile()
  const showAffiliates = features.affiliates

  return (
    <Shell onSkip={onSkip}>
      <div className="space-y-8">
        <TeamNote />
        <div className="grid gap-3 md:grid-cols-3 md:grid-rows-2">
          <ShareOnXCard
            featured
            onAction={onAction}
            className="md:col-span-2 md:row-span-2"
          />
          <ActionCard actionId="community" onAction={onAction} />
          <ActionCard actionId="contribute" onAction={onAction} />
          <ActionCard actionId="content" onAction={onAction} />
          {showAffiliates ? (
            <ActionCard actionId="affiliates" onAction={onAction} />
          ) : null}
          <ActionCard
            actionId="integrations"
            onAction={onAction}
            className={showAffiliates ? undefined : 'md:col-span-2'}
          />
        </div>
      </div>
    </Shell>
  )
}
