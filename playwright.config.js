import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    workers: 1,
    use: { baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000', channel: process.env.PLAYWRIGHT_CHANNEL || undefined, headless: true, screenshot: 'only-on-failure' },
    reporter: 'list',
});
