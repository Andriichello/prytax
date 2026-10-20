import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vitest/config';

/**
 * Unit tests of the SPA's helpers: the `*.test.ts` files next to the code they test. Vite's own config isn't used,
 * so Laravel's plugin and Tailwind stay out of the runs.
 */
export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        include: ['resources/js/**/*.test.ts'],
        environment: 'node',
    },
});
