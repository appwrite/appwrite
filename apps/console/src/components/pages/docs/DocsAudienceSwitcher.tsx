import { useEffect, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import {
  getDocsAudienceFromPathname,
  getDocsAudienceHomeHref,
  type DocsAudience,
} from '@/lib/docs/navigation'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { cn } from '@/lib/utils'
import { useConsoleProfile } from '@/hooks/use-console-profile'

const AUDIENCE_ANALYTICS = {
  developers: 'docs-audience-developers',
  partners: 'docs-audience-partners',
} as const

type DocsAudienceSwitcherProps = {
  pathname: string
  collapsed?: boolean
  isMobile?: boolean
  onNavigate?: () => void
}

const AUDIENCE_OPTIONS: Array<{ value: DocsAudience; label: string }> = [
  { value: 'developers', label: 'Developers' },
  { value: 'partners', label: 'Partners' },
]

export function DocsAudienceSwitcher({
  pathname,
  collapsed = false,
  isMobile = false,
  onNavigate,
}: DocsAudienceSwitcherProps) {
  const navigate = useNavigate()
  const { features } = useConsoleProfile()
  const audience = getDocsAudienceFromPathname(pathname)
  const [hasMounted, setHasMounted] = useState(false)

  useEffect(() => {
    setHasMounted(true)
  }, [])

  // Avoid SSR/client mismatches when partnersDocs is enabled only via
  // localStorage (unavailable during SSR) until the debug cookie is synced.
  if (!hasMounted || !features.partnersDocs) {
    return null
  }

  if (collapsed && !isMobile) {
    return null
  }

  return (
    <ToggleGroup
      type="single"
      variant="outline"
      size="sm"
      value={audience}
      onValueChange={(next) => {
        if (!next || next === audience) return
        const target = getDocsAudienceHomeHref(next as DocsAudience)
        navigate({ to: target })
        onNavigate?.()
      }}
      className="mb-6 w-full"
      aria-label="Documentation audience"
    >
      {AUDIENCE_OPTIONS.map((option) => (
        <ToggleGroupItem
          key={option.value}
          value={option.value}
          {...analyticsAttrs(AUDIENCE_ANALYTICS[option.value])}
          className={cn(
            'min-w-0 flex-1 px-2 text-[12px] font-medium',
            isMobile && 'text-[13px]',
          )}
        >
          {option.label}
        </ToggleGroupItem>
      ))}
    </ToggleGroup>
  )
}
