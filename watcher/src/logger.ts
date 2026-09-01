import pino from 'pino';

const level = process.env.LOG_LEVEL ?? 'info';

export const logger = pino({
  level,
  base: { service: 'watcher' },
  redact: {
    paths: ['req.headers["x-internal-token"]', 'headers["x-internal-token"]', 'INTERNAL_API_TOKEN'],
    censor: '[redacted]',
  },
});

export type Logger = pino.Logger;
