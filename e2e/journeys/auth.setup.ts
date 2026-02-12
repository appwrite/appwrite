import { expect, test } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { signInStep } from '../steps/account';

type StoredCookie = {
    name: string;
    expires?: number;
};

type StorageState = {
    cookies?: StoredCookie[];
};

function hasValidStoredSession(authPath: string): boolean {
    if (!fs.existsSync(authPath)) return false;

    try {
        const raw = fs.readFileSync(authPath, 'utf-8');
        const state = JSON.parse(raw) as StorageState;
        const sessionCookie = state.cookies?.find((cookie) => /session/i.test(cookie.name));
        if (!sessionCookie || typeof sessionCookie.expires !== 'number') {
            return false;
        }

        const nowSeconds = Math.floor(Date.now() / 1000);
        return sessionCookie.expires > nowSeconds + 60;
    } catch {
        return false;
    }
}

test('authenticate once and persist storage state', async ({ page, context }) => {
    const email = process.env.E2E_TEST_EMAIL;
    const password = process.env.E2E_TEST_PASSWORD;
    const isCI = !!process.env.CI;
    const allowCIReuse = process.env.E2E_REUSE_AUTH === 'true';

    if (!email || !password) {
        test.fail(true, 'E2E_TEST_EMAIL and E2E_TEST_PASSWORD must be set');
        return;
    }

    const authDir = path.join('e2e', '.auth');
    const authPath = path.join(authDir, 'auth.json');

    if ((!isCI || allowCIReuse) && hasValidStoredSession(authPath)) {
        return;
    }

    // Log in once and reuse storage state across tests to avoid creating new sessions/users.
    await signInStep(page, email, password);

    const cookies = await context.cookies();
    const sessionCookie = cookies.find((cookie) => /session/i.test(cookie.name));
    expect(sessionCookie, 'Expected a session cookie after login').toBeTruthy();

    fs.mkdirSync(authDir, { recursive: true });

    await context.storageState({ path: authPath });
});
