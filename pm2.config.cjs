/** @type {import('pm2').Config} */
module.exports = {
  apps: [
    {
      name: 'dev',
      cwd: '/app',
      script: 'bun run dev',
      exec_mode: 'fork',
      instances: 1,
      autorestart: true,
      watch: false,
      env_file: '.env',
      env: {
        PORT: '3000',
        APPWRITE_ENDPOINT: process.env.APPWRITE_ENDPOINT,
        APPWRITE_PROJECT_ID: process.env.APPWRITE_PROJECT_ID,
        APPWRITE_API_KEY: process.env.APPWRITE_API_KEY,
        VITE_INSTRUMENTATION_SCRIPT_SRC:
          process.env.VITE_INSTRUMENTATION_SCRIPT_SRC,
        DISABLE_HMR: 'true',
      },
    },
    {
      name: 'start',
      cwd: '/app',
      script: 'bun run start',
      exec_mode: 'fork',
      instances: 1,
      autorestart: true,
      watch: false,
      env_file: '.env',
      env: {
        PORT: '3000',
        APPWRITE_ENDPOINT: process.env.APPWRITE_ENDPOINT,
        APPWRITE_PROJECT_ID: process.env.APPWRITE_PROJECT_ID,
        APPWRITE_API_KEY: process.env.APPWRITE_API_KEY,
        VITE_INSTRUMENTATION_SCRIPT_SRC:
          process.env.VITE_INSTRUMENTATION_SCRIPT_SRC,
      },
    },
  ],
}
