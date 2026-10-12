import {
  getRateLimitDescription,
  type RateLimitDescription as RateLimitDescriptionData,
} from '@/lib/api-explorer/format-rate-limit'
import { MetadataTextMarkdown } from '@/components/global/shared/MetadataTextMarkdown'
import { cn } from '@/lib/utils'

type RateLimitDescriptionProps = {
  limit: number
  windowSeconds?: number
  rateKey?: string | string[]
  className?: string
}

export function RateLimitDescription({
  limit,
  windowSeconds = 3600,
  rateKey,
  className,
}: RateLimitDescriptionProps) {
  const description = getRateLimitDescription(limit, windowSeconds, rateKey)

  return (
    <RateLimitDescriptionContent description={description} className={className} />
  )
}

export function RateLimitDescriptionContent({
  description,
  className,
}: {
  description: RateLimitDescriptionData
  className?: string
}) {
  return (
    <div className={cn('space-y-1.5', className)}>
      <MetadataTextMarkdown
        content={description.text}
        className="text-[13px] leading-relaxed text-foreground"
      />

      {description.apiKeyNote ? (
        <MetadataTextMarkdown
          content={description.apiKeyNote}
          className="text-[12px] leading-relaxed text-muted-foreground"
        />
      ) : null}
    </div>
  )
}
