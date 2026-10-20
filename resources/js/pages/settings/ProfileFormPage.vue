<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, apiUrl } from '@/api/client';
import type { ApiResponse, MoneyFormat, Paginated, Profile, ProfileSettings, StoredFile } from '@/api/types';
import DateField from '@/components/form/DateField.vue';
import SelectField from '@/components/form/SelectField.vue';
import TextAreaField from '@/components/form/TextAreaField.vue';
import TextField from '@/components/form/TextField.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { errorMessage, fieldError } from '@/utils/errors';
import { formatAmount, setMoneyFormat } from '@/utils/money';

/**
 * Профіль ФОП (S-ProfileEdit), and a new ФОП: the taxpayer (owners change it; the РНОКПП is stored encrypted), the
 * addresses and contacts printed on invoices, the invoices' currency and language, the PDF signature, the money
 * formats of the app and of invoices, and whether the ФОП is active.
 */
const FORMATS: { value: MoneyFormat; label: string }[] = [
    { value: 'space_dot', label: '2 851.99' },
    { value: 'plain_dot', label: '2851.99' },
    { value: 'comma_dot', label: '2,851.99' },
    { value: 'plain_comma', label: '2851,99' },
    { value: 'space_comma', label: '2 851,99' },
    { value: 'dot_comma', label: '2.851,99' },
];
const CURRENCIES = ['USD', 'EUR', 'GBP', 'PLN', 'CHF', 'CAD', 'UAH'];

const route = useRoute();
const router = useRouter();
const { can } = useSession();
const { profileId, path, loadProfiles, loadSettings, select } = useCurrentProfile();

const isNew = computed(() => route.name === 'profile-new');
const canEditIdentity = computed(() => (isNew.value ? can('create', 'profiles') : can('update', 'profiles')));
const canConfigure = computed(() => isNew.value || can('configure', 'profiles'));

const form = reactive({
    full_name: '',
    full_name_en: '',
    tax_id: '',
    passport: false,
    registered_at: '',
    address: '',
    address_en: '',
    email: '',
    phone: '',
    default_currency: 'USD',
    locale: 'en',
    is_active: true,
    display_format: 'space_dot' as MoneyFormat,
    invoice_format: 'plain_dot' as MoneyFormat,
});

const signature = ref<StoredFile | null>(null);

const { data: existing, error: loadError, loading, reload } = useLoader(async (signal) => {
    if (isNew.value) {
        return null;
    }

    const [profile, settings, signatures] = await Promise.all([
        api.get<ApiResponse<Profile>>(`profiles/${profileId.value}`, undefined, signal),
        api.get<ApiResponse<ProfileSettings>>(path('settings'), undefined, signal),
        api.get<Paginated<StoredFile>>(path('signatures'), { page: { size: 10 } }, signal).catch(() => null),
    ]);

    Object.assign(form, {
        full_name: profile.data.full_name,
        full_name_en: profile.data.full_name_en ?? '',
        tax_id: profile.data.tax_id ?? '',
        passport: profile.data.tax_id_kind === 'passport',
        registered_at: profile.data.registered_at ?? '',
        address: profile.data.address ?? '',
        address_en: profile.data.address_en ?? '',
        email: profile.data.email ?? '',
        phone: profile.data.phone ?? '',
        default_currency: profile.data.default_currency,
        locale: profile.data.locale,
        is_active: profile.data.is_active,
        display_format: settings.data.display.money_format,
        invoice_format: settings.data.invoice.money_format,
    });
    signature.value = signatures?.data[signatures.data.length - 1] ?? null;

    return profile.data;
}, [profileId, isNew]);

const sample = (format: MoneyFormat, amount: string) => formatAmount(amount, { format });

/* The PDF signature: a PNG, which replaces the previous one */
const uploading = ref(false);
const signatureError = ref<string | null>(null);

async function uploadSignature(event: Event): Promise<void> {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) {
        return;
    }

    uploading.value = true;
    signatureError.value = null;

    try {
        const body = new FormData();

        body.append('file', file);

        const { data } = await api.post<ApiResponse<StoredFile>>(path('signatures'), body);
        const previous = signature.value;

        signature.value = data;

        if (previous) {
            await api.delete(path(`signatures/${previous.id}`)).catch(() => undefined);
        }
    } catch (caught) {
        signatureError.value = errorMessage(caught);
    } finally {
        uploading.value = false;
    }
}

/* Saving the profile, then its settings */
const saving = ref(false);
const saveError = ref<unknown>(null);

async function save(): Promise<void> {
    saving.value = true;
    saveError.value = null;

    const identity = {
        full_name: form.full_name.trim(),
        full_name_en: form.full_name_en.trim() || null,
        ...(form.tax_id && !form.tax_id.includes('•') ? { tax_id: form.tax_id.replace(/\s/g, '').toUpperCase() } : {}),
        tax_id_kind: form.passport ? 'passport' : 'rnokpp',
        registered_at: form.registered_at || null,
        address: form.address.trim() || null,
        address_en: form.address_en.trim() || null,
        email: form.email.trim() || null,
        phone: form.phone.trim() || null,
        default_currency: form.default_currency,
        locale: form.locale,
        is_active: form.is_active,
    };

    try {
        let id = profileId.value;

        if (isNew.value) {
            const { data } = await api.post<ApiResponse<Profile>>('profiles', identity);

            id = data.id;
        } else if (canEditIdentity.value) {
            await api.patch(`profiles/${id}`, identity);
        }

        if (canConfigure.value) {
            await api.patch(`profiles/${id}/settings`, {
                display: { money_format: form.display_format },
                invoice: { money_format: form.invoice_format },
            });
        }

        await loadProfiles();

        if (isNew.value && id !== null) {
            await select(id);
            await router.replace({ name: 'settings-taxes' });

            return;
        }

        await loadSettings();
        setMoneyFormat(form.display_format);
        await router.replace({ name: 'more' });
    } catch (caught) {
        saveError.value = caught;
    } finally {
        saving.value = false;
    }
}

const generalError = computed(() => {
    const fields = ['full_name', 'full_name_en', 'tax_id', 'tax_id_kind', 'registered_at', 'email', 'phone', 'address', 'address_en'];

    return saveError.value && !fields.some((field) => fieldError(saveError.value, field)) ? errorMessage(saveError.value) : null;
});
</script>

<template>
    <SubScreen :title="isNew ? 'Новий ФОП' : 'Профіль ФОП'" :back="{ name: 'more' }">
        <LoadState :loading="loading" :error="loadError" @retry="reload">
            <div class="flex items-start gap-3 rounded-control border border-line bg-surface px-3.5 py-3 text-13 text-ink-2">
                <span class="text-14 leading-tight font-semibold text-ink">3<br>група</span>
                <span>· спрощена система · без ПДВ · без працівників</span>
            </div>

            <section class="flex flex-col gap-3">
                <SectionTitle>Податкова особа</SectionTitle>
                <TextField v-model="form.full_name" label="ПІБ" :disabled="!canEditIdentity" :error="fieldError(saveError, 'full_name')" />
                <TextField v-model="form.full_name_en" label="ПІБ латиницею — для інвойсів" :disabled="!canEditIdentity" :error="fieldError(saveError, 'full_name_en')" />
                <div class="grid grid-cols-2 gap-2.5">
                    <TextField
                        v-model="form.tax_id"
                        :label="form.passport ? 'Паспорт' : 'РНОКПП'"
                        class="num"
                        :placeholder="existing?.tax_id_masked ?? ''"
                        :disabled="!canEditIdentity"
                        :error="fieldError(saveError, 'tax_id')"
                    />
                    <DateField v-model="form.registered_at" label="Дата реєстрації" :error="fieldError(saveError, 'registered_at')" />
                </div>
                <label class="flex min-h-11 items-center gap-3 text-15 text-ink-2">
                    <input v-model="form.passport" type="checkbox" class="size-5 accent-accent" :disabled="!canEditIdentity">
                    Серія й номер паспорта замість РНОКПП
                </label>
                <span class="-mt-2 text-12 text-ink-3">Зберігається зашифрованим, унікальний у межах організації</span>
            </section>

            <section class="flex flex-col gap-3">
                <SectionTitle>Адреса й контакти</SectionTitle>
                <TextAreaField v-model="form.address" label="Адреса" :rows="2" :error="fieldError(saveError, 'address')" />
                <TextAreaField v-model="form.address_en" label="Адреса англійською" :rows="2" :error="fieldError(saveError, 'address_en')" />
                <TextField v-model="form.email" label="Email" type="email" :error="fieldError(saveError, 'email')" />
                <TextField v-model="form.phone" label="Телефон" type="tel" placeholder="+380" :error="fieldError(saveError, 'phone')" />
            </section>

            <section class="flex flex-col gap-3">
                <SectionTitle>Інвойси</SectionTitle>
                <div class="grid grid-cols-2 gap-2.5">
                    <SelectField v-model="form.default_currency" label="Валюта" :options="CURRENCIES.map((currency) => ({ value: currency, label: currency }))" />
                    <SelectField v-model="form.locale" label="Мова документа" :options="[{ value: 'en', label: 'English' }, { value: 'uk', label: 'Українська' }]" />
                </div>
                <div v-if="!isNew" class="flex flex-col gap-1.5">
                    <span class="text-13 font-medium text-ink-2">Підпис у PDF</span>
                    <div v-if="signature" class="flex items-center gap-3 rounded-control border border-line bg-surface p-3">
                        <img :src="apiUrl(path(`signatures/${signature.id}/download`))" alt="Підпис" class="h-10 max-w-32 object-contain">
                        <span class="min-w-0 flex-1 truncate text-14 text-ink-2">{{ signature.name }}</span>
                    </div>
                    <label
                        v-if="canConfigure"
                        class="flex min-h-14 cursor-pointer flex-col items-center justify-center gap-1 rounded-control border border-dashed border-switch-off text-15 font-medium text-accent"
                    >
                        <Icon :name="uploading ? 'spinner' : 'upload'" :size="20" :class="uploading ? 'animate-spin' : ''" />
                        {{ signature ? 'Замінити підпис (PNG)' : 'Завантажити PNG підпису' }}
                        <input type="file" accept="image/png" class="sr-only" @change="uploadSignature">
                    </label>
                    <span v-if="signatureError" class="text-12 text-negative">{{ signatureError }}</span>
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <SectionTitle>Формат сум</SectionTitle>
                <SelectField
                    v-model="form.display_format"
                    label="У застосунку"
                    :disabled="!canConfigure"
                    :options="FORMATS.map((format) => ({ value: format.value, label: format.value === 'space_dot' ? `${format.label} — за замовчуванням` : format.label }))"
                />
                <SelectField
                    v-model="form.invoice_format"
                    label="В інвойсах (PDF)"
                    :disabled="!canConfigure"
                    :options="FORMATS.map((format) => ({ value: format.value, label: format.label }))"
                />
                <div class="flex flex-col gap-1 rounded-control bg-muted px-3.5 py-3 text-14 text-ink-2">
                    <span class="flex justify-between gap-3">Застосунок: <span class="num font-semibold text-ink">{{ sample(form.display_format, '1840135.05') }} ₴</span></span>
                    <span class="flex justify-between gap-3">Інвойс: <span class="num font-semibold text-ink">{{ sample(form.invoice_format, '3892.50') }} {{ form.default_currency }}</span></span>
                </div>
                <span class="text-12 text-ink-3">Окремо для застосунку та інвойсів. Розділювач копійок — крапка або кома.</span>
            </section>

            <label class="flex min-h-14 items-center justify-between gap-3 rounded-control border border-line bg-surface px-4">
                <span class="flex flex-col gap-0.5">
                    <span class="text-15">Активний ФОП</span>
                    <span class="text-12 text-ink-3">Неактивний зникає з панелей і термінів</span>
                </span>
                <input v-model="form.is_active" type="checkbox" class="size-5 accent-accent" :disabled="!canEditIdentity">
            </label>

            <p v-if="!canEditIdentity && !isNew" class="m-0 text-13 text-ink-3">Дані податкової особи змінює власник організації.</p>
            <p v-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>
        </LoadState>

        <template v-if="canEditIdentity || canConfigure" #footer>
            <PrimaryButton :busy="saving" :disabled="loading || !form.full_name.trim() || (isNew && !form.tax_id.trim())" @click="save">
                {{ isNew ? 'Створити ФОП' : 'Зберегти' }}
            </PrimaryButton>
        </template>
    </SubScreen>
</template>
