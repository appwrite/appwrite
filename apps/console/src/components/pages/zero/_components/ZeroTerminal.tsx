'use client'

import { useEffect, useState, type CSSProperties } from 'react'
import { RotateCcw } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import { ZERO_BUILD_MINUTES, ZERO_COMMAND } from '@/lib/zero/marketing-content'

const TYPE_MS = 45 * ZERO_COMMAND.length
const START_MS = 400

const lines: Array<{ text: string; tone?: 'ok' | 'muted' | 'link' }> = [
  {
    text: '✓ Your Appwrite Zero project is live → ./appwrite.config.json',
    tone: 'ok',
  },
  { text: '' },
  { text: '  Project    6721f0c4001e8b3a9d52' },
  { text: '  Endpoint   https://fra.cloud.appwrite.io/v1' },
  { text: '  Console    https://cloud.appwrite.io/console/project-fra-6721f…' },
  { text: '' },
  { text: '  You have 60 min to build. Claim it within 25h to keep it:' },
  {
    text: '    https://cloud.appwrite.io/transfer?transferId=6721f0c5…',
    tone: 'link',
  },
  { text: '' },
  { text: '  Next steps', tone: 'muted' },
  {
    text: '    appwrite tablesdb list       Call the project with its API key',
    tone: 'muted',
  },
  {
    text: '    appwrite push                Deploy this directory’s resources',
    tone: 'muted',
  },
  {
    text: '    appwrite zero status         Check the time left',
    tone: 'muted',
  },
]

function formatLeft(seconds: number) {
  const minutes = Math.floor(seconds / 60)
  const rest = seconds % 60
  return `${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`
}

export function ZeroTerminal() {
  const t = useT()
  const [run, setRun] = useState(0)
  const [left, setLeft] = useState(ZERO_BUILD_MINUTES * 60)

  useEffect(() => {
    const started = Date.now()
    setLeft(ZERO_BUILD_MINUTES * 60)
    const timer = window.setInterval(() => {
      const elapsed = Math.floor((Date.now() - started) / 1000)
      setLeft(Math.max(0, ZERO_BUILD_MINUTES * 60 - elapsed))
    }, 1000)
    return () => window.clearInterval(timer)
  }, [run])

  const outputStart = START_MS + TYPE_MS + 500

  return (
    <div
      data-ui
      className="overflow-hidden rounded-xl bg-[#1d1d21] text-[#ededf0] ring-1 ring-white/10"
    >
      <div className="flex items-center justify-between gap-3 bg-white/[0.04] px-4 py-2.5">
        <p className="text-[13px] text-white/70">{t('Terminal')}</p>
        <div className="flex items-center gap-3">
          <p className="text-[13px] tabular-nums text-white/70" aria-live="off">
            <span className="text-[var(--brand-cta)]">●</span>{' '}
            {t('Build time left')}: {formatLeft(left)}
          </p>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="h-7 gap-1.5 px-2 text-[12px] text-white/70 hover:bg-white/10 hover:text-white"
            onClick={() => setRun((value) => value + 1)}
          >
            <RotateCcw className="size-3.5" aria-hidden />
            {t('Replay')}
          </Button>
        </div>
      </div>
      <pre
        dir="ltr"
        key={run}
        className="zero-terminal overflow-x-auto px-4 py-4 font-mono text-[13px] leading-[1.7] sm:px-5"
        style={
          {
            '--zero-type-ms': `${TYPE_MS}ms`,
            '--zero-start-ms': `${START_MS}ms`,
            '--zero-chars': ZERO_COMMAND.length,
          } as CSSProperties
        }
      >
        <code>
          <span className="text-white/50">~/guestbook ❯ </span>
          <span className="zero-terminal-command">{ZERO_COMMAND}</span>
          {'\n\n'}
          {lines.map((line, index) => (
            <span
              key={index}
              className={
                line.tone === 'ok'
                  ? 'zero-terminal-line text-[#7ee2b8]'
                  : line.tone === 'link'
                    ? 'zero-terminal-line text-[#ff8fae]'
                    : line.tone === 'muted'
                      ? 'zero-terminal-line text-white/55'
                      : 'zero-terminal-line'
              }
              style={{ animationDelay: `${outputStart + index * 70}ms` }}
            >
              {line.text}
              {'\n'}
            </span>
          ))}
        </code>
      </pre>
    </div>
  )
}
