import type { SupportedLanguage } from '@/lib/i18n/active-language'

export type SpeakTextOptions = {
  lang?: SupportedLanguage | string
  onEnd?: () => void
  onError?: (error: Error) => void
}

const SPEECH_SYNTHESIS_LANG: Record<SupportedLanguage, string> = {
  en: 'en-US',
  he: 'he-IL',
  ja: 'ja-JP',
}

/** Pause between spoken chunks so consecutive sentences do not run together. */
const CHUNK_PAUSE_MS = 560

const LIST_ITEM_PATTERN =
  /^(?:[-*+•●◦▪▫–—]|\d+[.)])\s+(.+)$/u

let activeUtterance: SpeechSynthesisUtterance | null = null
let activeOnEnd: (() => void) | null = null
let speakGeneration = 0
let chunkPauseTimer: number | null = null

export function isSpeechSynthesisSupported(): boolean {
  if (typeof window === 'undefined') return false
  return (
    typeof window.speechSynthesis !== 'undefined' &&
    typeof window.SpeechSynthesisUtterance !== 'undefined'
  )
}

function resolveSpeechLang(lang?: SupportedLanguage | string): string {
  if (!lang) return SPEECH_SYNTHESIS_LANG.en
  if (lang in SPEECH_SYNTHESIS_LANG) {
    return SPEECH_SYNTHESIS_LANG[lang as SupportedLanguage]
  }
  return lang
}

function stripInlineMarkdown(markdown: string): string {
  return markdown
    .replace(/```[\s\S]*?```/g, '\n')
    .replace(/`([^`]+)`/g, '$1')
    .replace(/!\[([^\]]*)\]\([^)]+\)/g, '$1')
    .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
    .replace(/^#{1,6}\s+/gm, '')
    .replace(/(\*\*|__)(.*?)\1/g, '$2')
    .replace(/(\*|_)(.*?)\1/g, '$2')
    .replace(/^>\s?/gm, '')
}

function ensureSentence(text: string): string {
  const trimmed = text.trim()
  if (!trimmed) return ''
  return /[.!?…]$/u.test(trimmed) ? trimmed : `${trimmed}.`
}

function normalizeProse(text: string): string {
  return text
    .replace(/\s+/g, ' ')
    .replace(/\s+([,.;:!?])/g, '$1')
    .replace(/([.!?…]){2,}/gu, '$1')
    .trim()
}

function splitIntoSentences(text: string): string[] {
  const normalized = normalizeProse(text)
  if (!normalized) return []
  // Avoid lookbehind (`(?<=...)`) for broader Safari compatibility; split on
  // sentence-ending punctuation and keep the delimiter on the preceding chunk.
  const parts = normalized.split(/([.!?…]+)\s+/u)
  const sentences: string[] = []
  for (let i = 0; i < parts.length; i += 2) {
    const body = parts[i]?.trim() ?? ''
    const end = parts[i + 1] ?? ''
    const combined = `${body}${end}`.trim()
    if (combined) sentences.push(ensureSentence(combined))
  }
  return sentences
}

/**
 * Split markdown into spoken chunks. Each non-empty line is its own beat
 * (so "Tomorrow…" and "Met Office…" pause apart), and multi-sentence lines
 * are split further on sentence boundaries.
 */
export function toSpeechChunks(markdown: string): string[] {
  const chunks: string[] = []

  for (const rawLine of stripInlineMarkdown(markdown).split(/\n/)) {
    const line = rawLine.trim()
    if (!line) continue

    const listMatch = line.match(LIST_ITEM_PATTERN)
    const content = normalizeProse(listMatch?.[1] ?? line)
    if (!content) continue

    chunks.push(...splitIntoSentences(content))
  }

  return chunks
}

/** Strip markdown/code so TTS reads prose, not syntax. */
export function toSpeechText(markdown: string): string {
  return toSpeechChunks(markdown).join(' ')
}

function pickVoice(lang: string): SpeechSynthesisVoice | null {
  if (typeof window === 'undefined') return null
  const voices = window.speechSynthesis.getVoices()
  if (voices.length === 0) return null

  const exact = voices.find((voice) => voice.lang === lang)
  if (exact) return exact

  const prefix = lang.split('-')[0]?.toLowerCase()
  if (!prefix) return null
  return (
    voices.find((voice) => voice.lang.toLowerCase().startsWith(prefix)) ?? null
  )
}

function clearChunkPauseTimer() {
  if (chunkPauseTimer === null) return
  window.clearTimeout(chunkPauseTimer)
  chunkPauseTimer = null
}

export function stopSpeaking() {
  if (typeof window === 'undefined') return
  speakGeneration += 1
  clearChunkPauseTimer()
  const onEnd = activeOnEnd
  activeUtterance = null
  activeOnEnd = null
  window.speechSynthesis.cancel()
  // Some browsers do not reliably fire utterance end/error on cancel.
  onEnd?.()
}

export function speakText(text: string, options: SpeakTextOptions = {}) {
  if (!isSpeechSynthesisSupported()) {
    options.onError?.(new Error('Speech synthesis is not supported'))
    return
  }

  const chunks = toSpeechChunks(text)
  if (chunks.length === 0) {
    options.onEnd?.()
    return
  }

  stopSpeaking()

  const lang = resolveSpeechLang(options.lang)
  const generation = speakGeneration
  let chunkIndex = 0
  let finished = false

  const finish = (error?: Error) => {
    if (finished) return
    finished = true
    clearChunkPauseTimer()
    if (speakGeneration === generation) {
      activeUtterance = null
      activeOnEnd = null
    }
    if (error) {
      options.onError?.(error)
      return
    }
    options.onEnd?.()
  }

  activeOnEnd = () => finish()

  const speakChunkAt = (index: number) => {
    if (finished || speakGeneration !== generation) return
    if (index >= chunks.length) {
      finish()
      return
    }

    const utterance = new SpeechSynthesisUtterance(chunks[index])
    utterance.lang = lang
    const voice = pickVoice(lang)
    if (voice) {
      utterance.voice = voice
    }

    activeUtterance = utterance

    utterance.onend = () => {
      if (finished || speakGeneration !== generation) return
      const nextIndex = index + 1
      if (nextIndex >= chunks.length) {
        finish()
        return
      }
      // Explicit pause between chunks (list items and paragraph breaks).
      clearChunkPauseTimer()
      chunkPauseTimer = window.setTimeout(() => {
        chunkPauseTimer = null
        speakChunkAt(nextIndex)
      }, CHUNK_PAUSE_MS)
    }

    utterance.onerror = (event) => {
      if (event.error === 'canceled' || event.error === 'interrupted') {
        finish()
        return
      }
      finish(new Error(event.error || 'Speech synthesis failed'))
    }

    window.speechSynthesis.speak(utterance)
  }

  const startSpeaking = () => {
    if (finished || speakGeneration !== generation) return
    speakChunkAt(chunkIndex)
  }

  // Chrome may load voices asynchronously; wait briefly for voiceschanged.
  if (window.speechSynthesis.getVoices().length === 0) {
    const onVoicesChanged = () => {
      window.speechSynthesis.removeEventListener(
        'voiceschanged',
        onVoicesChanged,
      )
      startSpeaking()
    }
    window.speechSynthesis.addEventListener('voiceschanged', onVoicesChanged)
    window.setTimeout(() => {
      window.speechSynthesis.removeEventListener(
        'voiceschanged',
        onVoicesChanged,
      )
      startSpeaking()
    }, 250)
    return
  }

  startSpeaking()
}
