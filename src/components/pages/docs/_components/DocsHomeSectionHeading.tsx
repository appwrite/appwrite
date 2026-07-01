import type { ReactNode } from 'react'
import {
  docsPreviewSectionTitleClass,
} from '@/lib/docs/docs-container'
import { cn } from '@/lib/utils'

const DOCS_HOME_SECTION_TITLE_CLASS =
  'font-aeonik-pro text-balance text-[28px] font-normal leading-none tracking-tight text-foreground @[480px]:text-[32px]'

const DOCS_HOME_SECTION_DESCRIPTION_CLASS =
  'mt-4 max-w-3xl text-[14px] leading-6 text-muted-foreground @[480px]:text-[15px] @[480px]:leading-7'

const DOCS_PREVIEW_HOME_SECTION_DESCRIPTION_CLASS =
  'mt-2 max-w-3xl text-[12px] leading-5 text-muted-foreground @[480px]:mt-2.5 @[480px]:text-[13px] @[480px]:leading-6'

type DocsHomeSectionHeadingProps = {
  title: string
  description?: ReactNode
  className?: string
  variant?: 'page' | 'preview'
}

export function DocsHomeSectionHeading({
  title,
  description,
  className,
  variant = 'page',
}: DocsHomeSectionHeadingProps) {
  const titleClassName =
    variant === 'preview'
      ? docsPreviewSectionTitleClass
      : DOCS_HOME_SECTION_TITLE_CLASS
  const descriptionClassName =
    variant === 'preview'
      ? DOCS_PREVIEW_HOME_SECTION_DESCRIPTION_CLASS
      : DOCS_HOME_SECTION_DESCRIPTION_CLASS

  return (
    <div className={cn('max-w-2xl text-start', className)}>
      <h2 className={titleClassName}>
        {title}
        <span className="text-[var(--brand-cta)]">_</span>
      </h2>
      {description ? (
        typeof description === 'string' ? (
          <p className={descriptionClassName}>{description}</p>
        ) : (
          <div className={descriptionClassName}>{description}</div>
        )
      ) : null}
    </div>
  )
}
