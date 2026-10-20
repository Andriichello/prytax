<script setup lang="ts">
import AuthLayout from '@/components/layout/AuthLayout.vue';
</script>

<template>
    <AuthLayout>
        <div class="flex flex-col gap-4">
            <h1 class="m-0 text-22 font-semibold">Сторінку не знайдено</h1>
            <p class="m-0 text-15 leading-normal text-ink-2">Можливо, посилання застаріло або в ньому помилка.</p>
            <RouterLink to="/" class="flex min-h-11 items-center text-15 font-medium no-underline">На головну</RouterLink>
        </div>
    </AuthLayout>
</template>
