import type { ReactNode } from 'react'
import { Search } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { DomainsTldScatter } from '@/components/pages/domains/_components/DomainsTldScatter'
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
const DOMAIN_SEARCH_FOCUS_FIELD_CLASS =
  'h-14 rounded-xl ps-12 font-mono text-[15px] md:text-[16px] tracking-tight bg-background/90 border-foreground/15 shadow-[0_18px_50px_-24px_rgb(253_54_110/0.5)] backdrop-blur-sm focus-visible:border-[var(--brand-cta)]/50 focus-visible:ring-[var(--brand-cta)]/20 dark:bg-card/80'
const DOMAIN_SEARCH_HELPER_CLASS = 'text-[12px] leading-5 text-muted-foreground'
const FOCUS_MARKETING_CONTENT_WIDTH = 'max-w-2xl'

type DomainSearchResultsProps = {
  initialSearch?: string
  onSearchValueChange?: (value: string) => void
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
  /** Focus variant only: shown above the title before a search starts. */
  eyebrow?: ReactNode
  /** Focus variant only: shown below the search field before a search starts. */
  emptyState?: ReactNode
  /** Focus variant only: `compact` suits pages that already have an h1, like wizards. */
  heroSize?: 'marketing' | 'compact'
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
  onSubmitSearch,
  limitMessage,
  footer,
  className,
  prominent = false,
}: {
  inputId: string
  searchValue: string
  onSearchValueChange: (value: string) => void
  onSubmitSearch: () => void
  limitMessage?: ReactNode
  footer?: ReactNode
  className?: string
  prominent?: boolean
}) {
  const t = useT()
  const showFooterSlot = footer != null

  return (
    <div className={cn('w-full max-w-md space-y-2 text-start', className)}>
      <Label htmlFor={inputId} className={cn('text-[13px]', prominent && 'sr-only')}>
        {t('Domain name')}
      </Label>
      <div className="relative">
        <Search
          className={cn(
            'absolute top-1/2 -translate-y-1/2 text-muted-foreground',
            prominent ? 'start-4 z-[1] size-[18px]' : 'start-3 h-4 w-4',
          )}
        />
        <Input
          id={inputId}
          value={searchValue}
          onChange={(e) => onSearchValueChange(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter' && !e.nativeEvent.isComposing) {
              e.preventDefault()
              onSubmitSearch()
            }
          }}
          placeholder={t('e.g. mycompany or mycompany.com')}
          className={prominent ? DOMAIN_SEARCH_FOCUS_FIELD_CLASS : DOMAIN_SEARCH_FIELD_CLASS}
          autoFocus
        />
      </div>
      {limitMessage}
      {showFooterSlot ? (
        <div className={cn(DOMAIN_SEARCH_HELPER_CLASS, prominent && 'pt-1 text-center')}>{footer}</div>
      ) : null}
    </div>
  )
}

export function DomainSearchResults({
  initialSearch = '',
  onSearchValueChange,
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
  eyebrow,
  emptyState,
  heroSize = 'marketing',
}: DomainSearchResultsProps) {
  const t = useT()
  const isFocus = variant === 'focus'
  const isCompactHero = heroSize === 'compact'
  const HeroHeading = isCompactHero ? 'h2' : 'h1'
  const {
    searchValue,
    setSearchValue,
    submitSearch,
    suggestions,
    error,
    retry,
    isRetrying,
    hasContent,
    baseName,
    addRequestedTld,
  } = useDomainSearch(initialSearch, onSearchValueChange)

  const searchField = (
    <DomainSearchField
      inputId={inputId}
      searchValue={searchValue}
      onSearchValueChange={setSearchValue}
      onSubmitSearch={submitSearch}
      limitMessage={limitMessage}
      footer={!hasContent ? footer : undefined}
      className={isFocus ? 'max-w-none' : undefined}
      prominent={isFocus}
    />
  )

  return (
    <div
      className={cn(
        'relative flex min-h-0 flex-col',
        isFocus && 'min-h-[calc(100dvh-3.5rem)] overflow-x-clip',
        className,
      )}
    >
      {isFocus && !hasContent ? <DomainsTldScatter /> : null}
      <div
        className={cn(
          'relative z-[2] transition-[min-height,padding] duration-300 ease-out',
          hasContent
            ? cn(
                'min-h-0',
                isFocus && 'mx-auto w-full max-w-7xl px-4 pt-8 sm:px-6',
              )
            : isFocus
              ? cn(
                  'flex flex-1 flex-col items-center justify-center px-4 sm:px-6',
                  isCompactHero
                    ? 'pb-10 pt-8 sm:pt-10'
                    : 'pb-16 pt-12 sm:pt-16',
                )
              : compactEmptyState
                ? 'min-h-0'
                : 'flex min-h-[24dvh] flex-1 items-center justify-center sm:min-h-[32dvh]',
        )}
      >
        {isFocus ? (
          <>
            <div className={cn('mx-auto w-full', FOCUS_MARKETING_CONTENT_WIDTH)}>
              {!hasContent && (eyebrow || title || description) ? (
                <div className={cn('text-center', isCompactHero ? 'mb-6' : 'mb-9')}>
                  {eyebrow ? (
                    <div className={cn('flex justify-center', isCompactHero ? 'mb-4' : 'mb-6')}>
                      {eyebrow}
                    </div>
                  ) : null}
                  {title ? (
                    <HeroHeading
                      className={cn(
                        'font-aeonik-pro text-balance font-normal leading-[1.05] tracking-tight text-foreground',
                        isCompactHero
                          ? 'text-[30px] sm:text-[40px]'
                          : 'text-[34px] sm:text-[48px] lg:text-[56px]',
                      )}
                    >
                      {title}
                      <span className="text-[var(--brand-cta)]">_</span>
                    </HeroHeading>
                  ) : null}
                  {description ? (
                    <p
                      className={cn(
                        'mx-auto max-w-xl text-muted-foreground',
                        isCompactHero
                          ? 'mt-3.5 text-[14px] leading-6 sm:text-[15px] sm:leading-7'
                          : 'mt-5 text-[15px] leading-7 sm:text-[16px]',
                      )}
                    >
                      {description}
                    </p>
                  ) : null}
                </div>
              ) : null}
              {searchField}
            </div>
            {!hasContent && emptyState ? <div className="w-full">{emptyState}</div> : null}
          </>
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
          {error ? (
            <div role="alert" className="mb-3 flex items-center gap-3">
              <p className="text-[12px] text-destructive">
                {t('Failed to load domain prices. Please try again.')}
              </p>
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={isRetrying}
                onClick={() => void retry()}
              >
                {t('Retry')}
              </Button>
            </div>
          ) : null}
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
          ) : null}
        </div>
      ) : null}
    </div>
  )
}
