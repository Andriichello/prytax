<script setup lang="ts">
import AuthLayout from '@/components/layout/AuthLayout.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import { startupError } from '@/router';
import { errorMessage } from '@/utils/errors';

/**
 * The app's frame: one centred column, at most 480 px wide (the designs are drawn at 390 px). When the app can't
 * start (no connection, the server failed), it says so with a retry instead of a blank page.
 */
const retry = (): void => window.location.reload();
</script>

<template>
    <div class="mx-auto min-h-dvh w-full max-w-120 bg-paper">
        <AuthLayout v-if="startupError">
            <div class="flex flex-col gap-4">
                <h1 class="m-0 text-22 font-semibold">Не вдалося відкрити PryTax</h1>
                <p class="m-0 text-15 leading-normal text-ink-2" role="alert">{{ errorMessage(startupError) }}</p>
                <PrimaryButton @click="retry">Спробувати знову</PrimaryButton>
            </div>
        </AuthLayout>
        <RouterView v-else />
    </div>
</template>
