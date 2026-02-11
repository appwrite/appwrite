import { expect, test } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { signInStep } from '../steps/account';

test('authenticate once and persist storage state', async ({ page, context }) => {
    const email = process.env.E2E_TEST_EMAIL;
    const password = process.env.E2E_TEST_PASSWORD;

    if (!email || !password) {
        test.fail(true, 'E2E_TEST_EMAIL and E2E_TEST_PASSWORD must be set');
        return;
    }

    // Log in once and reuse storage state across tests to avoid creating new sessions/users.
    await signInStep(page, email, password);

    const cookies = await context.cookies();
    const sessionCookie = cookies.find((cookie) => /session/i.test(cookie.name));
    expect(sessionCookie, 'Expected a session cookie after login').toBeTruthy();

    const authDir = path.join('e2e', '.auth');
    fs.mkdirSync(authDir, { recursive: true });

    await context.storageState({ path: path.join(authDir, 'auth.json') });
});
