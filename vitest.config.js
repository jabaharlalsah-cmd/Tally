import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.js'],
        // The platform probe and the label localiser are module-level
        // singletons. restoreMocks keeps a stubbed navigator/matchMedia from
        // one file leaking into the next and silently poisoning detection.
        restoreMocks: true,
    },
});
