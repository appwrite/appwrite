import { z } from 'zod'

const envSchema = z
  .object({
    CI: z.string().optional(),
    VITE_APPWRITE_ENDPOINT: z
      .string()
      .min(1, 'VITE_APPWRITE_ENDPOINT is required'),
    E2E_TEST_SESSION_SECRET: z.string().min(1).optional(),
    /**
     * `X-Fallback-Cookies` JSON of a session CI creates once per workflow run,
     * so lanes share it instead of each signing in.
     */
    E2E_FALLBACK_COOKIES: z.string().min(1).optional(),
    E2E_TEST_EMAIL: z.string().email().optional(),
    E2E_TEST_PASSWORD: z.string().min(1).optional(),
    /**
     * Organization with a Pro (or higher) plan. Required for MySQL e2e suites
     * that create dedicated databases; also used as a smoke-test override.
     */
    E2E_ORG_ID: z.string().min(1).optional(),
    /** Optional override when the account has multiple projects. */
    E2E_PROJECT_ID: z.string().min(1).optional(),
  })
  .superRefine((value, ctx) => {
    if (!value.E2E_TEST_SESSION_SECRET && !value.E2E_FALLBACK_COOKIES) {
      if (!value.E2E_TEST_EMAIL) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          message:
            'E2E_TEST_EMAIL is required when E2E_TEST_SESSION_SECRET or E2E_FALLBACK_COOKIES is not set',
          path: ['E2E_TEST_EMAIL'],
        })
      }

      if (!value.E2E_TEST_PASSWORD) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          message:
            'E2E_TEST_PASSWORD is required when E2E_TEST_SESSION_SECRET or E2E_FALLBACK_COOKIES is not set',
          path: ['E2E_TEST_PASSWORD'],
        })
      }
    }
  })

const parsed = envSchema.safeParse(process.env)

if (!parsed.success) {
  const issues = parsed.error.issues
    .map((issue) => `- ${issue.path.join('.') || 'env'}: ${issue.message}`)
    .join('\n')

  throw new Error(`Invalid E2E environment variables:\n${issues}`)
}

export const env = {
  ...parsed.data,
  CI: parsed.data.CI === 'true' || parsed.data.CI === '1',
}
