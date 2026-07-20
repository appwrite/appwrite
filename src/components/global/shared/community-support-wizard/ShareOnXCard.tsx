'use client'

import { useEffect, useRef, useState } from 'react'
import {
  getCommunitySupportShareHref,
  shuffleCommunitySupportShareTexts,
  XBrandIcon,
  type CommunitySupportActionId,
} from '@/lib/community/support-prompt'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { ExternalLink, RefreshCw } from 'lucide-react'

type ShareOnXCardProps = {
  onAction: (actionId: CommunitySupportActionId) => void
  className?: string
  /** Larger featured treatment for the magazine layout. */
  featured?: boolean
}

export function ShareOnXCard({
  onAction,
  className,
  featured = false,
}: ShareOnXCardProps) {
  const t = useT()
  const { profileId } = useConsoleProfile()
  const [deck, setDeck] = useState(() =>
    shuffleCommunitySupportShareTexts(profileId),
  )
  const [deckIndex, setDeckIndex] = useState(0)
  const [shareText, setShareText] = useState(() => deck[0] ?? '')
  const [textKey, setTextKey] = useState(0)
  const shouldSelectAllOnFocusRef = useRef(true)
  const trimmed = shareText.trim()
  const canPost = trimmed.length > 0

  useEffect(() => {
    const nextDeck = shuffleCommunitySupportShareTexts(profileId)
    setDeck(nextDeck)
    setDeckIndex(0)
    setShareText(nextDeck[0] ?? '')
    setTextKey((key) => key + 1)
    shouldSelectAllOnFocusRef.current = true
  }, [profileId])

  const tryAnotherExample = () => {
    const nextIndex = deckIndex + 1
    if (nextIndex >= deck.length) {
      const reshuffled = shuffleCommunitySupportShareTexts(profileId, shareText)
      setDeck(reshuffled)
      setDeckIndex(0)
      setShareText(reshuffled[0] ?? '')
    } else {
      setDeckIndex(nextIndex)
      setShareText(deck[nextIndex] ?? '')
    }
    setTextKey((key) => key + 1)
    shouldSelectAllOnFocusRef.current = true
  }

  return (
    <div
      className={cn(
        'flex h-full flex-col rounded-xl border border-border bg-card/50 p-4 text-start',
        featured && 'rounded-2xl p-5 min-h-[280px]',
        className,
      )}
    >
      <div className="flex items-start gap-3">
        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
          <XBrandIcon className="h-4 w-4" />
        </div>
        <div className="min-w-0 flex-1 space-y-1">
          <div className="flex items-center gap-1.5">
            <p
              className={cn(
                'font-semibold text-foreground',
                featured ? 'text-[20px]' : 'text-[13px]',
              )}
            >
              {t('Spread the word on X')}
            </p>
            <ExternalLink className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
          </div>
          <p className="text-[12px] text-muted-foreground leading-snug">
            {t(
              'Write something true to your experience, or start from one of these examples.',
            )}
          </p>
        </div>
      </div>

      <Textarea
        key={textKey}
        value={shareText}
        onChange={(event) => setShareText(event.target.value)}
        onFocus={(event) => {
          if (!shouldSelectAllOnFocusRef.current) return
          shouldSelectAllOnFocusRef.current = false
          event.currentTarget.select()
        }}
        className={cn(
          'mt-3 min-h-[96px] resize-y text-[13px] animate-in fade-in-0 duration-200',
          featured && 'min-h-[120px] flex-1',
        )}
        maxLength={280}
        aria-label={t('Share message')}
      />

      <div className="mt-3 flex items-center justify-between gap-3">
        <div className="flex min-w-0 items-center gap-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            data-analytics-track="false"
            onClick={tryAnotherExample}
          >
            <RefreshCw className="me-1.5 h-3.5 w-3.5" />
            {t('Try another example')}
          </Button>
          <p className="text-[11px] tabular-nums text-muted-foreground">
            {shareText.length}/280
          </p>
        </div>
        <Button
          type="button"
          size="sm"
          className="h-9 text-[13px]"
          disabled={!canPost}
          data-analytics-track="manual"
          onClick={() => {
            if (!canPost) return
            onAction('share')
            window.open(
              getCommunitySupportShareHref(trimmed),
              '_blank',
              'noopener,noreferrer',
            )
          }}
        >
          <XBrandIcon className="me-1.5 h-3.5 w-3.5" />
          {t('Spread the word')}
        </Button>
      </div>
    </div>
  )
}
