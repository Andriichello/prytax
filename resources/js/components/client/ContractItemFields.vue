<script setup lang="ts">
import { computed, ref } from 'vue';
import type { ContractForm } from '@/components/client/ContractFormModel';
import { t } from '@/i18n';
import { describeContract } from '@/utils/contracts';

/**
 * The template of the invoices' item description: the text with its placeholder chips, and how it reads on an invoice.
 */
const { error = undefined } = defineProps<{ error?: string }>();

const form = defineModel<ContractForm>({ required: true });

const TOKENS = ['{name}', '{number}', '{started_at:F j, Y}'];

const template = ref<HTMLTextAreaElement | null>(null);

function insertToken(token: string): void {
    const element = template.value;
    const start = element?.selectionStart ?? form.value.description_template.length;
    const end = element?.selectionEnd ?? start;

    form.value.description_template = form.value.description_template.slice(0, start) + token + form.value.description_template.slice(end);
}

const preview = computed(() => describeContract(form.value.description_template, {
    name: form.value.name,
    number: form.value.number,
    started_at: form.value.started_at,
    ends_at: form.value.ends_at,
    currency: form.value.currency,
    payment_terms_days: form.value.payment_terms_days,
}));
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <label for="contract-template" class="text-13 font-medium text-ink-2">{{ t('clients.contract.itemDescriptionTemplate') }}</label>
        <textarea
            id="contract-template"
            ref="template"
            v-model="form.description_template"
            rows="3"
            class="num resize-none rounded-control border bg-surface px-3.5 py-3 text-15 leading-[1.45] whitespace-normal"
            :class="error ? 'border-negative' : 'border-line-input'"
        />
        <span v-if="error" class="text-12 text-negative">{{ error }}</span>
        <div class="flex flex-wrap gap-2">
            <button
                v-for="token in TOKENS"
                :key="token"
                type="button"
                class="num flex min-h-10 items-center rounded-control border border-line-input bg-surface px-3 text-14"
                @click="insertToken(token)"
            >
                {{ token }}
            </button>
        </div>
        <div v-if="preview" class="flex flex-col gap-1 rounded-control bg-muted px-3.5 py-3">
            <span class="text-12 text-ink-3">{{ t('clients.contract.invoicePreview') }}</span>
            <span class="text-15 leading-[1.45]">{{ preview }}</span>
        </div>
    </div>
</template>
