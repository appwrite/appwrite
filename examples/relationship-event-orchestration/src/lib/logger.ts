import pino from 'pino';

export const logger = pino({
  level: process.env.LOG_LEVEL ?? 'info',
  base: { service: 'relationship-event-orchestration' },
  redact: ['req.headers.authorization', 'apiKey', 'env.APPWRITE_API_KEY'],
  timestamp: pino.stdTimeFunctions.isoTime,
});

export type Logger = typeof logger;
