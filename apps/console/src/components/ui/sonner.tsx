'use client'

import { useTheme } from 'next-themes'
import { Toaster as Sonner, type ToasterProps } from 'sonner'
import { usePageDirection } from '@/lib/layout/page-direction'

/** Matches Tailwind `end-4` and the project progress panel offset. */
const TOAST_INLINE_END_OFFSET = '1rem'

const toasterInlineEndStyle = {
  right: 'auto',
  left: 'auto',
  insetInlineStart: 'auto',
  insetInlineEnd: TOAST_INLINE_END_OFFSET,
} as const satisfies React.CSSProperties

const toastInlineEndStyle = {
  right: 'auto',
  left: 'auto',
  insetInlineStart: 'auto',
  insetInlineEnd: 0,
} as const satisfies React.CSSProperties

const Toaster = ({
  style,
  toastOptions,
  dir,
  offset,
  ...props
}: ToasterProps) => {
  const { theme = 'system' } = useTheme()
  const pageDirection = usePageDirection()

  return (
    <Sonner
      theme={theme as ToasterProps['theme']}
      dir={dir ?? pageDirection}
      className="toaster toaster-at-inline-end group"
      offset={offset ?? TOAST_INLINE_END_OFFSET}
      style={
        {
          ...toasterInlineEndStyle,
          '--normal-bg': 'var(--popover)',
          '--normal-text': 'var(--popover-foreground)',
          '--normal-border': 'var(--border)',
          ...style,
        } as React.CSSProperties
      }
      toastOptions={{
        ...toastOptions,
        style: {
          ...toastInlineEndStyle,
          ...toastOptions?.style,
        },
      }}
      {...props}
    />
  )
}

export { Toaster }
