import { z } from 'zod'

const envSchema = z
  .object({
    CI: z.string().optional(),
    VITE_APPWRITE_ENDPOINT: z
      .string()
      .min(1, 'VITE_APPWRITE_ENDPOINT is required'),
    E2E_TEST_SESSION_SECRET: z.string().min(1).optional(),
    E2E_TEST_EMAIL: z.string().email().optional(),
    E2E_TEST_PASSWORD: z.string().min(1).optional(),
  })
  .superRefine((value, ctx) => {
    if (!value.E2E_TEST_SESSION_SECRET) {
      if (!value.E2E_TEST_EMAIL) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          message:
            'E2E_TEST_EMAIL is required when E2E_TEST_SESSION_SECRET is not set',
          path: ['E2E_TEST_EMAIL'],
        })
      }

      if (!value.E2E_TEST_PASSWORD) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          message:
            'E2E_TEST_PASSWORD is required when E2E_TEST_SESSION_SECRET is not set',
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
