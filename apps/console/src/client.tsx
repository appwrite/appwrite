// First, before the rest of the bundle runs: the home hero's factory starts
// building (see `early.ts`). Otherwise TanStack Start's default client entry.
import './components/pages/home/factory/early'
import { StrictMode, startTransition } from 'react'
import { hydrateRoot } from 'react-dom/client'
import { StartClient } from '@tanstack/react-start/client'

startTransition(() => {
  hydrateRoot(
    document,
    <StrictMode>
      <StartClient />
    </StrictMode>,
  )
})
