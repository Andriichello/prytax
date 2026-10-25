<script setup lang="ts">
import { computed, ref } from 'vue';
import type { InvoiceDocumentSettings, InvoiceLocale } from '@/api/types';
import InvoiceStylePicker from '@/components/invoice/InvoiceStylePicker.vue';
import SelectField from '@/components/form/SelectField.vue';
import SwitchCard from '@/components/form/SwitchCard.vue';
import Icon from '@/components/ui/Icon.vue';
import { type InvoiceDocumentContext, useInvoiceDocument } from '@/composables/useInvoiceDocument';
import { LOCALE_NAMES, t } from '@/i18n';
import { styleName, styleThumbnail } from '@/utils/invoiceStyles';

/**
 * The look of an invoice's document, as an open accordion group edits it (design.md §16.3, «Стиль, мова й договір»):
 * the style (a row with its thumbnail, which opens the picker), the language and the contract section, each with
 * where its value comes from and «Скинути» of the level's own one. At the ФОП's level the language is its document
 * language, which is never empty.
 */
const {
    context,
    subtitle,
    target = {},
    localeRequired = false,
    errors = {},
} = defineProps<{
    context: InvoiceDocumentContext;
    /** What the style picker names under its title: the ФОП, the client, or «Інвойс BL-2026-10-31-14». */
    subtitle: string;
    /** The invoice, or the client whose latest invoice, the style preview renders. */
    target?: { client_id?: number | null; invoice_id?: number | null };
    localeRequired?: boolean;
    errors?: Partial<Record<keyof InvoiceDocumentSettings, string | undefined>>;
}>();

const model = defineModel<InvoiceDocumentSettings>({ required: true });

const doc = useInvoiceDocument(model, () => context);

const picking = ref(false);

const localeOptions = computed(() => (['en', 'uk'] as const).map((code) => ({ value: code, label: LOCALE_NAMES[code] })));

const showContract = computed({
    get: () => doc.showContract.value,
    set: (value: boolean) => doc.set({ show_contract: value }),
});
</script>

<template>
    <div class="flex flex-col gap-3.5">
        <div class="flex flex-col gap-1.5">
            <span class="text-13 font-medium text-ink-2">{{ t('invoices.document.style') }}</span>
            <button
                type="button"
                class="flex min-h-16 items-center gap-3 rounded-control border border-line-input bg-surface py-1.5 pr-3.5 pl-2 text-left"
                @click="picking = true"
            >
                <img
                    :src="styleThumbnail(doc.style.value, doc.locale.value)"
                    alt=""
                    class="h-13 w-9.5 shrink-0 rounded-[3px] border border-line bg-white object-cover"
                >
                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span class="truncate text-16 font-medium text-ink">{{ styleName(doc.style.value) }}</span>
                    <span class="truncate text-12 text-ink-3">{{ doc.styleSource.value }}</span>
                </span>
                <Icon name="chevronRight" :size="20" class="shrink-0 text-ink-2" />
            </button>
            <button
                v-if="model.style !== null"
                type="button"
                class="min-h-8 self-end text-13 font-medium text-accent"
                @click="doc.set({ style: null })"
            >
                {{ t('invoices.document.reset') }}
            </button>
        </div>

        <SelectField
            :model-value="model.locale"
            :label="t('invoices.document.language')"
            :aside="localeRequired || model.locale === null ? undefined : doc.localeSource.value"
            :options="localeOptions"
            :placeholder="localeRequired ? undefined : doc.localeInherited.value"
            :clearable="!localeRequired"
            :hint="localeRequired ? t('invoices.document.languageHint') : undefined"
            :error="errors.locale"
            @update:model-value="doc.set({ locale: $event as InvoiceLocale | null })"
        />

        <SwitchCard v-model="showContract" :title="t('invoices.document.showContract')" :sub="doc.contractSource.value">
            <template v-if="model.show_contract !== null" #aside>
                ·
                <button type="button" class="font-medium text-accent" @click="doc.set({ show_contract: null })">{{ t('invoices.document.reset') }}</button>
            </template>
        </SwitchCard>

        <InvoiceStylePicker
            v-model:open="picking"
            :level="context.level"
            :subtitle="subtitle"
            :locale="doc.locale.value"
            :current="model.style"
            :inherited="context.inherited?.style ?? null"
            :target="target"
            @choose="doc.set({ style: $event })"
        />
    </div>
</template>
