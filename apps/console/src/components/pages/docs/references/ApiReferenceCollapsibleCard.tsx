'use client'

import type { ReactNode } from 'react'
import { ChevronDown } from 'lucide-react'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import type { ApiReferenceCardId } from '@/lib/docs/references/api-reference-ui-prefs'
import { useApiReferenceUiPrefs } from '@/lib/docs/references/ApiReferenceUiPrefsProvider'

type ApiReferenceCollapsibleCardProps = {
  title: string
  cardId: ApiReferenceCardId
  className?: string
  children: ReactNode
}

export function ApiReferenceCollapsibleCard({
  title,
  cardId,
  className,
  children,
}: ApiReferenceCollapsibleCardProps) {
  const { prefs, setCardOpen } = useApiReferenceUiPrefs()
  const open = prefs.cards[cardId]

  return (
    <Collapsible
      open={open}
      onOpenChange={(nextOpen) => setCardOpen(cardId, nextOpen)}
      className={className}
    >
      <div className="overflow-hidden rounded-lg border border-border bg-background">
        <CollapsibleTrigger className="group flex w-full cursor-pointer items-center justify-between gap-3 px-4 py-2.5 text-start transition-colors hover:bg-muted/30 data-[state=open]:border-b data-[state=open]:border-border">
          <h4 className="text-[13px] font-semibold tracking-tight text-foreground">
            {title}
          </h4>
          <ChevronDown className="size-4 shrink-0 text-muted-foreground transition-transform group-data-[state=open]:rotate-180" />
        </CollapsibleTrigger>
        <CollapsibleContent>{children}</CollapsibleContent>
      </div>
    </Collapsible>
  )
}
