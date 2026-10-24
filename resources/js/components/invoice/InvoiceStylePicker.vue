<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { InvoiceLocale, InvoiceStyle, SettingSource } from '@/api/types';
import InvoiceStylePreview from '@/components/invoice/InvoiceStylePreview.vue';
import FullScreenSheet from '@/components/ui/FullScreenSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import { t } from '@/i18n';
import {
    DEFAULT_INVOICE_STYLE,
    INVOICE_STYLE_GROUPS,
    INVOICE_STYLES,
    type InvoiceStyleGroup,
    styleName,
    styleThumbnail,
} from '@/utils/invoiceStyles';

/**
 * Стиль інвойсу: the catalogue of styles by group, as thumbnails in the level's invoice language, with "Як у ФОП" /
 * "Як у клієнта" on top at the client's and invoice's levels (which clears the level's own style). "Переглянути" opens
 * the chosen style's preview; "Обрати" gives the choice back to the form, which saves it with its own button.
 */
const { level, subtitle, locale, current, inherited, target } = defineProps<{
    level: 'invoice' | 'client' | 'profile';
    /** The ФОП's name, the client's name or "Інвойс BL-2026-10-31-14". */
    subtitle: string;
    /** The level's invoice language, in which the thumbnails are. */
    locale: InvoiceLocale;
    /** The level's own style; null takes the one below. */
    current: InvoiceStyle | null;
    /** The style of the levels below, and where it comes from. */
    inherited: { value: InvoiceStyle; source: SettingSource } | null;
    /** What the preview renders. */
    target: { client_id?: number | null; invoice_id?: number | null };
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ choose: [style: InvoiceStyle | null] }>();

/** The chosen style, or null for the one below (only at the client's and invoice's levels). */
const selected = ref<InvoiceStyle | null>(current);
const previewing = ref(false);

watch(open, (isOpen) => {
    if (isOpen) {
        selected.value = level === 'profile' ? current ?? inherited?.value ?? DEFAULT_INVOICE_STYLE : current;
    }
});

/** The style, which the choice prints. */
const effective = computed<InvoiceStyle>(() => selected.value ?? inherited?.value ?? DEFAULT_INVOICE_STYLE);

const intro = computed(() => {
    switch (level) {
        case 'invoice':
            return t('invoices.document.pickerIntroInvoice');
        case 'client':
            return t('invoices.document.pickerIntroClient');
        default:
            return t('invoices.document.pickerIntroProfile');
    }
});

/** "Як у ФОП" or "Як у клієнта", with what that is now. */
const inheritOption = computed(() => {
    const fromClient = level === 'invoice' && inherited?.source === 'client';

    return {
        label: fromClient ? t('invoices.document.asClient') : t('invoices.document.asProfile'),
        hint: fromClient
            ? t('invoices.document.followsClient', { name: styleName(inherited?.value) })
            : t('invoices.document.followsProfile', { name: styleName(inherited?.value) }),
    };
});

function groupName(group: InvoiceStyleGroup): string {
    switch (group) {
        case 'classic':
            return t('invoices.document.groupClassic');
        case 'geometric':
            return t('invoices.document.groupGeometric');
        default:
            return t('invoices.document.groupAndriichello');
    }
}

const groups = computed(() => INVOICE_STYLE_GROUPS.map((group) => ({
    group,
    styles: INVOICE_STYLES.filter((style) => style.group === group),
})));

function choose(style: InvoiceStyle | null = selected.value): void {
    emit('choose', style);
    open.value = false;
}

/** The preview's choice: the style itself, even when it is the inherited one. */
function chooseFromPreview(style: InvoiceStyle): void {
    selected.value = style;
    choose(style);
}
</script>

<template>
    <FullScreenSheet v-model:open="open" :title="t('invoices.document.style')" :subtitle="subtitle">
        <p class="m-0 text-15 leading-normal text-ink-2">{{ intro }}</p>

        <button
            v-if="level !== 'profile'"
            type="button"
            role="radio"
            :aria-checked="selected === null ? 'true' : 'false'"
            class="flex min-h-15 items-center gap-3 rounded-card border bg-surface px-4 py-3 text-left"
            :class="selected === null ? 'border-accent ring-1 ring-accent' : 'border-line'"
            @click="selected = null"
        >
            <span
                aria-hidden="true"
                class="flex size-5.5 shrink-0 items-center justify-center rounded-full border-2"
                :class="selected === null ? 'border-accent' : 'border-line-input'"
            >
                <span v-if="selected === null" class="size-2.5 rounded-full bg-accent" />
            </span>
            <span class="flex min-w-0 flex-col gap-0.5">
                <span class="text-16 font-medium">{{ inheritOption.label }}</span>
                <span class="text-13 text-ink-3">{{ inheritOption.hint }}</span>
            </span>
        </button>

        <section v-for="{ group, styles } in groups" :key="group" class="flex flex-col gap-3">
            <h2 class="m-0 text-12 font-semibold tracking-[0.08em] text-ink-3 uppercase">{{ groupName(group) }}</h2>
            <div class="grid grid-cols-2 gap-3" role="radiogroup" :aria-label="groupName(group)">
                <button
                    v-for="style in styles"
                    :key="style.key"
                    type="button"
                    role="radio"
                    :aria-checked="selected === style.key ? 'true' : 'false'"
                    class="relative flex flex-col gap-2 rounded-card border bg-surface p-2.5 text-left"
                    :class="selected === style.key ? 'border-accent ring-2 ring-accent' : 'border-line'"
                    @click="selected = style.key"
                >
                    <img
                        :src="styleThumbnail(style.key, locale)"
                        :alt="style.name"
                        loading="lazy"
                        class="aspect-[210/297] w-full rounded-[3px] border border-line bg-white object-cover"
                    >
                    <span class="flex items-center justify-between gap-1.5 px-0.5">
                        <span class="truncate text-15 font-medium">{{ style.name }}</span>
                        <span v-if="style.key === DEFAULT_INVOICE_STYLE" class="shrink-0 rounded-badge bg-muted px-2 py-0.5 text-12 text-ink-2">
                            {{ t('invoices.document.asDefault') }}
                        </span>
                    </span>
                    <span
                        v-if="selected === style.key"
                        aria-hidden="true"
                        class="absolute top-4 right-4 flex size-7 items-center justify-center rounded-full bg-accent text-white"
                    >
                        <Icon name="check" :size="16" />
                    </span>
                </button>
            </div>
        </section>

        <template #footer>
            <div class="flex gap-2.5">
                <SecondaryButton class="flex-1" @click="previewing = true">
                    <Icon name="eye" :size="20" />
                    {{ t('invoices.document.preview') }}
                </SecondaryButton>
                <PrimaryButton class="flex-1" @click="choose()">{{ t('invoices.document.choose', { name: styleName(effective) }) }}</PrimaryButton>
            </div>
        </template>
    </FullScreenSheet>

    <InvoiceStylePreview v-model:open="previewing" :style="effective" :locale="locale" :target="target" @choose="chooseFromPreview" />
</template>
