<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { ApiResponse, Client, InvoiceDocumentSettings, Paginated, Profile, ProfileSettings } from '@/api/types';
import InvoiceDocumentFields from '@/components/invoice/InvoiceDocumentFields.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { dateParts, today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import type { PurposeSample } from '@/utils/invoiceDocument';

/**
 * Документ інвойсу: the ФОП's invoice defaults (design.md §4.1), which its clients and invoices take unless they have
 * their own — the language (the ФОП's document language, which the owner changes), the payment purpose, the act note
 * and the contract section. Each empty one takes the built-in default. The currency is the ФОП's default currency.
 */
const router = useRouter();
const { can } = useSession();
const { profileId, path, loadProfiles, loadSettings } = useCurrentProfile();

const form = ref<InvoiceDocumentSettings>({ locale: 'en', payment_purpose_template: null, act_note_enabled: null, act_note: null, show_contract: null });
const profile = ref<Profile | null>(null);
const sampleClient = ref<Client | null>(null);

const { error: loadError, loading, reload } = useLoader(async (signal) => {
    const [owner, settings, clients] = await Promise.all([
        api.get<ApiResponse<Profile>>(`profiles/${profileId.value}`, { append: ['effective_invoice_settings', 'invoice_texts'] }, signal),
        api.get<ApiResponse<ProfileSettings>>(path('settings'), undefined, signal),
        api.get<Paginated<Client>>(path('clients'), { page: { size: 1 } }, signal),
    ]);
    const { payment_purpose_template, act_note_enabled, act_note, show_contract } = settings.data.invoice;

    profile.value = owner.data;
    sampleClient.value = clients.data[0] ?? null;
    form.value = { locale: owner.data.locale, payment_purpose_template, act_note_enabled, act_note, show_contract };

    return true;
}, [profileId]);

const canConfigure = computed(() => can('configure', 'profiles'));
const canEditLanguage = computed(() => can('update', 'profiles'));

/** The built-in settings, which the ФОП's take where it has none of its own. */
const inherited = computed(() => {
    const settings = profile.value?.effective_invoice_settings;

    return settings
        ? {
            locale: settings.locale.inherited,
            payment_purpose_template: settings.payment_purpose_template.inherited,
            act_note_enabled: settings.act_note_enabled.inherited,
            act_note: settings.act_note.inherited,
            show_contract: settings.show_contract.inherited,
        }
        : null;
});

/** An invoice of the first client for this month, on which the payment purpose is previewed. */
const sample = computed<PurposeSample>(() => {
    const { year, month } = dateParts(today());
    const end = new Date(Date.UTC(year, month, 0)).toISOString().slice(0, 10);

    return {
        number: `${sampleClient.value?.abbreviation ?? 'BL'}-${end}-1`,
        issuedAt: end,
        periodStart: `${end.slice(0, 8)}01`,
        periodEnd: end,
        contract: null,
        client: sampleClient.value,
        profile: profile.value,
    };
});

const saving = ref(false);
const saveError = ref<unknown>(null);

async function save(): Promise<void> {
    saving.value = true;
    saveError.value = null;

    try {
        if (canEditLanguage.value && form.value.locale && form.value.locale !== profile.value?.locale) {
            await api.patch(`profiles/${profileId.value}`, { locale: form.value.locale });
            await loadProfiles();
        }

        await api.patch(path('settings'), {
            invoice: {
                payment_purpose_template: form.value.payment_purpose_template?.trim() || null,
                act_note_enabled: form.value.act_note_enabled,
                act_note: form.value.act_note?.trim() || null,
                show_contract: form.value.show_contract,
            },
        });
        await loadSettings();
        await router.replace({ name: 'more' });
    } catch (caught) {
        saveError.value = caught;
    } finally {
        saving.value = false;
    }
}

const fieldErrors = computed(() => ({
    locale: fieldError(saveError.value, 'locale'),
    payment_purpose_template: fieldError(saveError.value, 'invoice.payment_purpose_template'),
    act_note: fieldError(saveError.value, 'invoice.act_note'),
}));
const generalError = computed(() => (saveError.value && !Object.values(fieldErrors.value).some(Boolean) ? errorMessage(saveError.value) : null));
</script>

<template>
    <SubScreen :title="t('settings.document.title')" :back="{ name: 'more' }">
        <LoadState :loading="loading" :error="loadError" @retry="reload">
            <p class="m-0 text-15 leading-normal text-ink-2">{{ t('settings.document.explained') }}</p>

            <InvoiceDocumentFields
                v-model="form"
                level="profile"
                :inherited="inherited"
                :texts="profile?.invoice_texts ?? null"
                :sample="sample"
                :currency="profile?.default_currency ?? null"
                locale-required
                :locale-disabled="!canEditLanguage"
                :locale-hint="canEditLanguage ? undefined : t('settings.document.languageByOwner')"
                :disabled="!canConfigure"
                :errors="fieldErrors"
            />

            <p v-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>
        </LoadState>

        <template v-if="canConfigure" #footer>
            <PrimaryButton :busy="saving" :disabled="loading" @click="save">{{ t('core.common.save') }}</PrimaryButton>
        </template>
    </SubScreen>
</template>
