import type { ReactNode } from 'react'
import { Search } from 'lucide-react'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { DomainSuggestionCard } from '@/components/pages/organizations/$orgId/domains/_components/DomainSuggestionCard'
import {
  type DomainSelectionQuote,
  type DomainSuggestion,
  useDomainSearch,
} from '@/lib/domains/search'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const DOMAIN_SEARCH_FIELD_CLASS =
  'h-11 ps-10 font-mono text-[14px] tracking-tight bg-muted/30 border-border/80 focus:bg-background'
const DOMAIN_SEARCH_HELPER_CLASS = 'text-[12px] leading-5 text-muted-foreground'
const FOCUS_MARKETING_CONTENT_WIDTH = 'max-w-2xl'

type DomainSearchResultsProps = {
  initialSearch?: string
  onSelectDomain: (full: string, quote: DomainSelectionQuote) => void
  limitReached?: boolean
  limitMessage?: ReactNode
  actionLabel?: string
  inputId?: string
  className?: string
  compactEmptyState?: boolean
  variant?: 'default' | 'focus'
  title?: string
  description?: string
  footer?: ReactNode
}

function getSelectionQuote(suggestion: DomainSuggestion): DomainSelectionQuote {
  return {
    price: suggestion.price,
    periodYears: suggestion.periodYears,
    premium: suggestion.premium,
    renewalPrice: suggestion.renewalPrice,
    renewalPeriodYears: suggestion.renewalPeriodYears,
  }
}

function DomainSearchField({
  inputId,
  searchValue,
  onSearchValueChange,
  limitMessage,
  footer,
  className,
}: {
  inputId: string
  searchValue: string
  onSearchValueChange: (value: string) => void
  limitMessage?: ReactNode
  footer?: ReactNode
  className?: string
}) {
  const t = useT()
  const showFooterSlot = footer != null

  return (
    <div className={cn('w-full max-w-md space-y-2 text-start', className)}>
      <Label htmlFor={inputId} className="text-[13px]">
        {t('Domain name')}
      </Label>
      <div className="relative">
        <Search className="absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          id={inputId}
          value={searchValue}
          onChange={(e) => onSearchValueChange(e.target.value)}
          placeholder={t('e.g. mycompany or mycompany.com')}
          className={DOMAIN_SEARCH_FIELD_CLASS}
          autoFocus
        />
      </div>
      {limitMessage}
      {showFooterSlot ? (
        <div className={DOMAIN_SEARCH_HELPER_CLASS}>{footer}</div>
      ) : null}
    </div>
  )
}

export function DomainSearchResults({
  initialSearch = '',
  onSelectDomain,
  limitReached = false,
  limitMessage,
  actionLabel = 'Add',
  inputId = 'domain-search',
  className,
  compactEmptyState = false,
  variant = 'default',
  title,
  description,
  footer,
}: DomainSearchResultsProps) {
  const t = useT()
  const isFocus = variant === 'focus'
  const {
    searchValue,
    setSearchValue,
    suggestions,
    error,
    hasContent,
    baseName,
    addRequestedTld,
  } = useDomainSearch(initialSearch)

  const searchField = (
    <DomainSearchField
      inputId={inputId}
      searchValue={searchValue}
      onSearchValueChange={setSearchValue}
      limitMessage={limitMessage}
      footer={!hasContent ? footer : undefined}
      className={isFocus ? 'max-w-none' : undefined}
    />
  )

  return (
    <div
      className={cn(
        'flex min-h-0 flex-col',
        isFocus && 'min-h-[calc(100dvh-3.5rem)]',
        className,
      )}
    >
      <div
        className={cn(
          'transition-[min-height,padding] duration-300 ease-out',
          hasContent
            ? cn('min-h-0', isFocus && 'mx-auto w-full max-w-7xl px-4 pt-8 sm:px-6')
            : isFocus
              ? 'flex flex-1 flex-col items-center justify-center px-4 pb-10 pt-6 sm:px-6 sm:pt-8'
              : compactEmptyState
                ? 'min-h-0'
                : 'flex min-h-[24dvh] flex-1 items-center justify-center sm:min-h-[32dvh]',
        )}
      >
        {isFocus ? (
          <div
            className={cn(
              'mx-auto w-full',
              FOCUS_MARKETING_CONTENT_WIDTH,
              !hasContent && '-translate-y-6 sm:-translate-y-8',
            )}
          >
            {!hasContent && (title || description) ? (
              <div className="mb-8 space-y-3 text-center">
                {title ? (
                  <h1 className="font-aeonik-pro whitespace-nowrap text-[clamp(1.125rem,2.5vw+0.75rem,2.25rem)] font-normal leading-none tracking-tight text-foreground">
                    {title}
                    <span className="text-[var(--brand-cta)]">_</span>
                  </h1>
                ) : null}
                {description ? (
                  <p className="text-[13px] leading-6 text-muted-foreground">
                    {description}
                  </p>
                ) : null}
              </div>
            ) : null}
            {searchField}
          </div>
        ) : (
          searchField
        )}
      </div>

      {hasContent ? (
        <div
          className={cn(
            'mt-6 flex-1 min-h-0',
            isFocus && 'mx-auto w-full max-w-7xl px-4 pb-10 sm:px-6',
          )}
        >
          {suggestions.length > 0 ? (
            <div className="grid grid-cols-1 items-stretch gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
              {suggestions.map((suggestion) => (
                <DomainSuggestionCard
                  key={suggestion.full}
                  suggestion={suggestion}
                  onSelect={(full) =>
                    onSelectDomain(full, getSelectionQuote(suggestion))
                  }
                  onVisible={() => addRequestedTld(suggestion.tld)}
                  disabled={limitReached}
                  disabledReason={limitReached ? 'limit' : undefined}
                  actionLabel={actionLabel}
                />
              ))}
            </div>
          ) : baseName.length > 0 && baseName.length < 2 ? (
            <p className={DOMAIN_SEARCH_HELPER_CLASS}>
              {t('Type at least 2 characters to see suggestions')}
            </p>
          ) : baseName.length >= 2 && error ? (
            <p className="text-[12px] text-destructive">
              {t('Failed to load domain prices. Please try again.')}
            </p>
          ) : null}
        </div>
      ) : null}
    </div>
  )
}
