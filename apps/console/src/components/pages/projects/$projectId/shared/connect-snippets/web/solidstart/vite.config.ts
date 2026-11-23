import { defineConfig } from 'vite'
import { nitro } from 'nitro/vite'
import { solidStart } from '@solidjs/start/config'

export default defineConfig({
  plugins: [solidStart({ ssr: false }), nitro()],
})
