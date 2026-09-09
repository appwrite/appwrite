import { useMemo } from 'react'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { emailPreviewDocument } from '@/lib/email-preview'

type EmailPreviewProps = {
  content: string
  html: boolean
  className?: string
}

/**
 * Renders an email body the way a mail client would, inside a fully sandboxed
 * frame: no scripts, no same-origin access, no popups, no top navigation.
 */
export function EmailPreview({ content, html, className }: EmailPreviewProps) {
  const t = useT()
  const srcDoc = useMemo(
    () => emailPreviewDocument(content, html),
    [content, html],
  )

  if (content.trim() === '') {
    return (
      <div
        className={cn(
          'flex items-center justify-center rounded-md border border-dashed border-border text-[13px] text-muted-foreground',
          className,
        )}
      >
        {t('Nothing to preview')}
      </div>
    )
  }

  return (
    <iframe
      title={t('Email preview')}
      srcDoc={srcDoc}
      sandbox=""
      referrerPolicy="no-referrer"
      className={cn(
        'block w-full rounded-md border border-border bg-white',
        className,
      )}
    />
  )
}
