import { useEffect, useState } from 'react'

export type InitDayCountdownState = {
  label: string
  isComplete: boolean
}

export function formatInitDayCountdown(remainingMs: number): InitDayCountdownState {
  if (remainingMs <= 0) {
    return { label: 'Unlocking now', isComplete: true }
  }

  const totalSeconds = Math.floor(remainingMs / 1000)
  const days = Math.floor(totalSeconds / 86_400)
  const hours = Math.floor((totalSeconds % 86_400) / 3_600)
  const minutes = Math.floor((totalSeconds % 3_600) / 60)
  const seconds = totalSeconds % 60

  if (days > 0) {
    return { label: `${days}d ${hours}h ${minutes}m`, isComplete: false }
  }

  if (hours > 0) {
    return { label: `${hours}h ${minutes}m ${seconds}s`, isComplete: false }
  }

  return { label: `${minutes}m ${seconds}s`, isComplete: false }
}

export function useInitDayCountdown(unlockAt: Date): InitDayCountdownState {
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    const interval = window.setInterval(() => {
      setNow(Date.now())
    }, 1_000)

    return () => window.clearInterval(interval)
  }, [unlockAt.getTime()])

  return formatInitDayCountdown(unlockAt.getTime() - now)
}
