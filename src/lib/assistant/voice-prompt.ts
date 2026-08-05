import type { SupportedLanguage } from '@/lib/i18n/active-language'

type SpeechRecognitionResultLike = {
  isFinal: boolean
  0?: { transcript?: string }
}

type SpeechRecognitionEventLike = Event & {
  resultIndex: number
  results: ArrayLike<SpeechRecognitionResultLike>
}

type SpeechRecognitionErrorEventLike = Event & {
  error: string
}

type SpeechRecognitionLike = {
  continuous: boolean
  interimResults: boolean
  lang: string
  onresult: ((event: SpeechRecognitionEventLike) => void) | null
  onerror: ((event: SpeechRecognitionErrorEventLike) => void) | null
  onend: (() => void) | null
  start: () => void
  stop: () => void
  abort: () => void
}

type SpeechRecognitionConstructor = new () => SpeechRecognitionLike

/** Number of studio-style meter bars exposed by {@link VoicePromptSession.getLevels}. */
export const VOICE_LEVEL_BAR_COUNT = 32

export type VoicePromptSession = {
  stop: () => void
  /** Latest normalized bar levels (0–1). Safe to call from `requestAnimationFrame`. */
  getLevels: () => number[]
}

export type StartVoicePromptOptions = {
  lang?: SupportedLanguage | string
  onInterim: (text: string) => void
  onFinal: (text: string) => void
  onError: (error: Error) => void
  onEnd: () => void
}

const SPEECH_RECOGNITION_LANG: Record<SupportedLanguage, string> = {
  en: 'en-US',
  he: 'he-IL',
  ja: 'ja-JP',
}

/**
 * Spoken phrase that starts a cancelable submit countdown when it ends the
 * transcript (e.g. "… create a bucket submit now").
 *
 * Prefer "submit now" over "send now": Web Speech often hears "send" as "sent".
 */
export const VOICE_SUBMIT_TRIGGER_BY_LANG: Record<SupportedLanguage, string> = {
  en: 'submit now',
  he: 'שלח עכשיו',
  ja: '今すぐ送信',
}

const EMPTY_LEVELS = Object.freeze(
  Array.from({ length: VOICE_LEVEL_BAR_COUNT }, () => 0),
) as number[]

/** Normalize transcript text for trigger matching. */
export function normalizeVoiceTranscript(text: string): string {
  return text
    .trim()
    .toLowerCase()
    .replace(/[\p{P}\p{S}]+$/gu, '')
    .replace(/\s+/g, ' ')
}

function voiceSubmitTriggersForLang(lang?: SupportedLanguage): string[] {
  const primary =
    VOICE_SUBMIT_TRIGGER_BY_LANG[lang ?? 'en'] ??
    VOICE_SUBMIT_TRIGGER_BY_LANG.en
  // Always accept English "submit now" too; people often keep the English command.
  return Array.from(
    new Set([
      normalizeVoiceTranscript(primary),
      normalizeVoiceTranscript('submit now'),
    ]),
  ).filter(Boolean)
}

/** True when the transcript ends with the voice submit trigger phrase. */
export function voiceTranscriptEndsWithSubmitTrigger(
  text: string,
  lang?: SupportedLanguage,
): boolean {
  const normalized = normalizeVoiceTranscript(text)
  if (!normalized) return false
  return voiceSubmitTriggersForLang(lang).some((trigger) => {
    if (normalized === trigger) return true
    return normalized.endsWith(` ${trigger}`)
  })
}

/**
 * Locate a trailing voice submit trigger in the raw transcript.
 * Returns character offsets into `text` for the matched phrase (not punctuation).
 */
export function findTrailingVoiceSubmitTriggerRange(
  text: string,
  lang?: SupportedLanguage,
): { start: number; end: number } | null {
  const triggers = voiceSubmitTriggersForLang(lang).sort(
    (a, b) => b.length - a.length,
  )
  const trimEndCount = text.length - text.trimEnd().length
  const working = trimEndCount > 0 ? text.slice(0, -trimEndCount) : text
  for (const trigger of triggers) {
    const escaped = trigger
      .replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
      .replace(/\s+/g, '\\s+')
    const match = new RegExp(`(^|\\s)(${escaped})([\\p{P}\\p{S}]*)$`, 'iu').exec(
      working,
    )
    if (!match || match.index == null) continue
    const start = match.index + match[1].length
    const end = start + match[2].length
    return { start, end }
  }
  return null
}

/** Remove a trailing voice submit trigger phrase before sending. */
export function stripVoiceSubmitTrigger(
  text: string,
  lang?: SupportedLanguage,
): string {
  const range = findTrailingVoiceSubmitTriggerRange(text, lang)
  if (!range) return text.trim()
  return `${text.slice(0, range.start)}${text.slice(range.end)}`
    .replace(/[\p{P}\p{S}]+$/gu, '')
    .trim()
}

function getSpeechRecognitionConstructor():
  | SpeechRecognitionConstructor
  | undefined {
  if (typeof window === 'undefined') return undefined
  const speechWindow = window as Window & {
    SpeechRecognition?: SpeechRecognitionConstructor
    webkitSpeechRecognition?: SpeechRecognitionConstructor
  }
  return (
    speechWindow.SpeechRecognition ?? speechWindow.webkitSpeechRecognition
  )
}

export function isVoicePromptSupported(): boolean {
  if (typeof window === 'undefined') return false
  if (!navigator.mediaDevices?.getUserMedia) return false
  return Boolean(getSpeechRecognitionConstructor())
}

function resolveSpeechLang(lang?: SupportedLanguage | string): string {
  if (!lang) return SPEECH_RECOGNITION_LANG.en
  if (lang in SPEECH_RECOGNITION_LANG) {
    return SPEECH_RECOGNITION_LANG[lang as SupportedLanguage]
  }
  return lang
}

function stopMediaStream(stream: MediaStream | null) {
  stream?.getTracks().forEach((track) => track.stop())
}

function getAudioContextConstructor(): typeof AudioContext | undefined {
  if (typeof window === 'undefined') return undefined
  return (
    window.AudioContext ||
    (
      window as Window & {
        webkitAudioContext?: typeof AudioContext
      }
    ).webkitAudioContext
  )
}

/**
 * Clear UI cue when voice recording starts or stops. Uses Web Audio so we
 * don't need asset files; failures are ignored (autoplay / closed context).
 */
function playVoicePromptCue(kind: 'start' | 'end') {
  const AudioContextCtor = getAudioContextConstructor()
  if (!AudioContextCtor) return

  try {
    const context = new AudioContextCtor()
    const now = context.currentTime
    // Two short tones (up for start, down for end) so the cue is easy to hear.
    const notes =
      kind === 'start'
        ? [
            { freq: 880, at: 0, dur: 0.085 },
            { freq: 1175, at: 0.095, dur: 0.11 },
          ]
        : [
            { freq: 1040, at: 0, dur: 0.085 },
            { freq: 660, at: 0.095, dur: 0.12 },
          ]
    const peak = kind === 'start' ? 0.18 : 0.15
    const totalMs = (notes[notes.length - 1]!.at + notes[notes.length - 1]!.dur + 0.08) * 1000

    for (const note of notes) {
      const oscillator = context.createOscillator()
      const gain = context.createGain()
      oscillator.type = 'sine'
      oscillator.frequency.setValueAtTime(note.freq, now + note.at)
      oscillator.connect(gain)
      gain.connect(context.destination)

      const startAt = now + note.at
      const endAt = startAt + note.dur
      gain.gain.setValueAtTime(0.0001, startAt)
      gain.gain.exponentialRampToValueAtTime(peak, startAt + 0.01)
      gain.gain.exponentialRampToValueAtTime(0.0001, endAt)

      oscillator.start(startAt)
      oscillator.stop(endAt + 0.02)
    }

    const close = () => {
      void context.close().catch(() => {})
    }
    window.setTimeout(close, totalMs)

    if (context.state === 'suspended') {
      void context.resume().catch(() => {})
    }
  } catch {
    // Best-effort feedback only.
  }
}

function createAudioLevelMonitor(stream: MediaStream): {
  getLevels: () => number[]
  stop: () => void
} {
  const AudioContextCtor = getAudioContextConstructor()

  if (!AudioContextCtor) {
    return {
      getLevels: () => EMPTY_LEVELS.slice(),
      stop: () => {},
    }
  }

  const audioContext = new AudioContextCtor()
  const source = audioContext.createMediaStreamSource(stream)
  const analyser = audioContext.createAnalyser()
  analyser.fftSize = 128
  analyser.smoothingTimeConstant = 0.72
  analyser.minDecibels = -75
  analyser.maxDecibels = -20
  source.connect(analyser)

  const frequencyData = new Uint8Array(analyser.frequencyBinCount)
  const levels = new Array<number>(VOICE_LEVEL_BAR_COUNT).fill(0)
  // Soft decay so bars ease down instead of snapping to silence.
  const displayLevels = new Array<number>(VOICE_LEVEL_BAR_COUNT).fill(0)

  const getLevels = () => {
    if (audioContext.state === 'closed') return EMPTY_LEVELS.slice()

    analyser.getByteFrequencyData(frequencyData)
    const binCount = frequencyData.length
    // Skip the very lowest bins (often DC / rumble) for a clearer voice shape.
    const startBin = Math.max(1, Math.floor(binCount * 0.04))
    const usableBins = Math.max(1, binCount - startBin)

    for (let i = 0; i < VOICE_LEVEL_BAR_COUNT; i += 1) {
      // Emphasize mid bars (voice energy) with a mild center weight.
      const t = i / (VOICE_LEVEL_BAR_COUNT - 1)
      const mirrored = 1 - Math.abs(t * 2 - 1)
      const centerWeight = 0.55 + mirrored * 0.45

      const binStart =
        startBin + Math.floor((i / VOICE_LEVEL_BAR_COUNT) * usableBins)
      const binEnd =
        startBin +
        Math.floor(((i + 1) / VOICE_LEVEL_BAR_COUNT) * usableBins)
      let sum = 0
      let count = 0
      for (let bin = binStart; bin < Math.max(binStart + 1, binEnd); bin += 1) {
        sum += frequencyData[bin] ?? 0
        count += 1
      }
      const raw = count > 0 ? sum / count / 255 : 0
      // Mild curve so quiet speech still moves the meter.
      const shaped = Math.pow(Math.min(1, raw * 1.35), 0.85) * centerWeight
      levels[i] = shaped
      displayLevels[i] =
        shaped > displayLevels[i]
          ? shaped
          : displayLevels[i] * 0.82 + shaped * 0.18
    }

    return displayLevels.slice()
  }

  const stop = () => {
    try {
      source.disconnect()
    } catch {
      // Already disconnected.
    }
    try {
      analyser.disconnect()
    } catch {
      // Already disconnected.
    }
    void audioContext.close().catch(() => {})
  }

  // Some browsers start AudioContext suspended until a user gesture resumes it.
  if (audioContext.state === 'suspended') {
    void audioContext.resume().catch(() => {})
  }

  return { getLevels, stop }
}

/**
 * Ask for microphone permission via getUserMedia, keep the stream open for a
 * live level meter, and record the spoken prompt with the Web Speech API.
 */
export async function startVoicePrompt(
  options: StartVoicePromptOptions,
): Promise<VoicePromptSession> {
  const Recognition = getSpeechRecognitionConstructor()
  if (!Recognition || !navigator.mediaDevices?.getUserMedia) {
    throw new Error('Voice input is not supported in this browser')
  }

  let stream: MediaStream | null = null
  try {
    stream = await navigator.mediaDevices.getUserMedia({
      audio: {
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true,
      },
    })
  } catch (error) {
    if (
      error instanceof DOMException &&
      (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError')
    ) {
      throw new Error('Microphone permission denied')
    }
    throw new Error('Could not start voice input')
  }

  const levelMonitor = createAudioLevelMonitor(stream)

  const recognition = new Recognition()
  recognition.continuous = true
  recognition.interimResults = true
  recognition.lang = resolveSpeechLang(options.lang)

  let stopped = false
  let finalTranscript = ''

  const teardown = () => {
    levelMonitor.stop()
    stopMediaStream(stream)
    stream = null
  }

  const session: VoicePromptSession = {
    getLevels: () => levelMonitor.getLevels(),
    stop: () => {
      if (stopped) return
      stopped = true
      try {
        recognition.stop()
      } catch {
        try {
          recognition.abort()
        } catch {
          // Ignore abort failures during teardown.
        }
      }
      teardown()
    },
  }

  recognition.onresult = (event) => {
    let interim = ''
    for (let i = event.resultIndex; i < event.results.length; i += 1) {
      const result = event.results[i]
      const transcript = result?.[0]?.transcript?.trim() ?? ''
      if (!transcript) continue
      if (result.isFinal) {
        finalTranscript = [finalTranscript, transcript]
          .filter(Boolean)
          .join(' ')
        options.onFinal(finalTranscript)
      } else {
        interim = [interim, transcript].filter(Boolean).join(' ')
      }
    }
    if (interim) {
      options.onInterim(
        [finalTranscript, interim].filter(Boolean).join(' '),
      )
    }
  }

  recognition.onerror = (event) => {
    if (stopped) return
    // `aborted` / `no-speech` are expected when the user stops or pauses.
    if (event.error === 'aborted' || event.error === 'no-speech') {
      return
    }
    stopped = true
    teardown()
    if (event.error === 'not-allowed') {
      options.onError(new Error('Microphone permission denied'))
      return
    }
    options.onError(new Error('Could not start voice input'))
  }

  recognition.onend = () => {
    if (!stopped) {
      stopped = true
      teardown()
    }
    playVoicePromptCue('end')
    options.onEnd()
  }

  try {
    recognition.start()
  } catch {
    stopped = true
    teardown()
    throw new Error('Could not start voice input')
  }

  playVoicePromptCue('start')
  return session
}
