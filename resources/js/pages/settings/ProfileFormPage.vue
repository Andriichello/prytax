<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, apiUrl } from '@/api/client';
import type { ApiResponse, Paginated, Profile, ProfileSettings, StoredFile } from '@/api/types';
import AccordionGroup from '@/components/form/AccordionGroup.vue';
import DateField from '@/components/form/DateField.vue';
import Dimmable from '@/components/form/Dimmable.vue';
import ReadRow from '@/components/form/ReadRow.vue';
import SwitchCard from '@/components/form/SwitchCard.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import ProfileContactFields from '@/components/profile/ProfileContactFields.vue';
import ProfileFormatFields from '@/components/profile/ProfileFormatFields.vue';
import { DOCUMENT_LOCALES, profileFields, profileForm, type ProfileForm, type ProfileGroup } from '@/components/profile/ProfileFormModel';
import ProfileInvoiceFields from '@/components/profile/ProfileInvoiceFields.vue';
import ProfilePersonFields from '@/components/profile/ProfilePersonFields.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SideNote from '@/components/ui/SideNote.vue';
import { provideAccordion } from '@/composables/useAccordion';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { formatDate, today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { formatAmount, setMoneyFormat } from '@/utils/money';

/**
 * Профіль ФОП (M-ProfileEdit, design.md §16.5), and a new ФОП. The static banner of the 3rd group, then the groups:
 * «Податкова особа» (the РНОКПП is stored encrypted, owners change it), «Адреса й контакти», «Інвойси» (the currency,
 * the document's language and the PDF signature, which uploads at once; an accountant opens it for the signature
 * only) and «Формат сум» (settings); then the
 * «Активний ФОП» switch, which saves at once, with the date an inactive ФОП closed (its taxes are tracked until then).
 * A new ФОП is one form, which creates it and goes on to its taxes.
 */
const route = useRoute();
const router = useRouter();
const { can } = useSession();
const { profileId, path, loadProfiles, loadSettings, select } = useCurrentProfile();
const accordion = provideAccordion();

const isNew = computed(() => route.name === 'profile-new');
const canEditIdentity = computed(() => (isNew.value ? can('create', 'profiles') : can('update', 'profiles')));
const canConfigure = computed(() => isNew.value || can('configure', 'profiles'));

const profile = ref<Profile | null>(null);
const settings = ref<ProfileSettings | null>(null);
const signature = ref<StoredFile | null>(null);
/** The saved profile, which the closed groups show, and the open group's copy. */
const saved = ref<ProfileForm>(profileForm(null, null));
const form = ref<ProfileForm>(profileForm(null, null));

const { error: loadError, loading, reload } = useLoader(async (signal) => {
    if (isNew.value) {
        return null;
    }

    const [loaded, loadedSettings, signatures] = await Promise.all([
        api.get<ApiResponse<Profile>>(`profiles/${profileId.value}`, undefined, signal),
        api.get<ApiResponse<ProfileSettings>>(path('settings'), undefined, signal),
        api.get<Paginated<StoredFile>>(path('signatures'), { page: { size: 10 } }, signal).catch(() => null),
    ]);

    profile.value = loaded.data;
    settings.value = loadedSettings.data;
    signature.value = signatures?.data[signatures.data.length - 1] ?? null;
    saved.value = profileForm(loaded.data, loadedSettings.data);
    form.value = profileForm(loaded.data, loadedSettings.data);

    return loaded.data;
}, [profileId, isNew]);

/* The PDF signature: a PNG, which replaces the previous one */
const uploading = ref(false);
const signatureError = ref<unknown>(null);

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
        signatureError.value = caught;
    } finally {
        uploading.value = false;
    }
}

/* Saving a group */
const saving = ref(false);
const saveError = ref<unknown>(null);

function open(): void {
    form.value = { ...saved.value };
    saveError.value = null;
}

async function saveGroup(group: ProfileGroup): Promise<void> {
    saving.value = true;
    saveError.value = null;

    try {
        if (group === 'formats') {
            await api.patch(path('settings'), {
                display: { money_format: form.value.display_format },
                invoice: { money_format: form.value.invoice_format },
            });
            await loadSettings();
            setMoneyFormat(form.value.display_format);
        } else if (canEditIdentity.value) {
            await api.patch(`profiles/${profileId.value}`, profileFields(form.value, group));
            await loadProfiles();
        }

        await reload();
        accordion.open.value = null;
    } catch (caught) {
        saveError.value = caught;
    } finally {
        saving.value = false;
    }
}

/* «Активний ФОП»: switched off, the ФОП closes today; switched on again, it is open */
const switching = ref(false);
const switchError = ref<string | null>(null);

async function saveActive(changes: { is_active?: boolean; closed_at?: string }): Promise<void> {
    switching.value = true;
    switchError.value = null;

    try {
        const isActive = changes.is_active ?? profile.value?.is_active ?? true;

        await api.patch(`profiles/${profileId.value}`, {
            is_active: isActive,
            closed_at: isActive ? null : changes.closed_at ?? profile.value?.closed_at ?? today(),
        });
        await loadProfiles();
        await reload();
    } catch (caught) {
        switchError.value = errorMessage(caught);
    } finally {
        switching.value = false;
    }
}

/* A new ФОП: one form, its profile, then its settings */
const creating = ref(false);

/** A new ФОП, which was created although saving its settings failed: trying again updates it, not another one. */
let createdId: number | null = null;

async function create(): Promise<void> {
    creating.value = true;
    saveError.value = null;

    try {
        const fields = { ...profileFields(form.value, 'all'), is_active: true };

        if (createdId === null) {
            const { data } = await api.post<ApiResponse<Profile>>('profiles', fields);

            createdId = data.id;
        } else {
            await api.patch(`profiles/${createdId}`, fields);
        }

        await api.patch(`profiles/${createdId}/settings`, {
            display: { money_format: form.value.display_format },
            invoice: { money_format: form.value.invoice_format },
        });
        await loadProfiles();
        await select(createdId);
        await router.replace({ name: 'settings-taxes' });
    } catch (caught) {
        saveError.value = caught;
    } finally {
        creating.value = false;
    }
}

const errors = computed(() => Object.fromEntries(
    ['full_name', 'full_name_en', 'tax_id', 'registered_at', 'address', 'address_en', 'email', 'phone']
        .map((field) => [field, fieldError(saveError.value, field)]),
));
const generalError = computed(() => (saveError.value && !Object.values(errors.value).some(Boolean) ? errorMessage(saveError.value) : null));

const sample = (format: ProfileForm['display_format'], amount: string): string => formatAmount(amount, { format });
</script>

<template>
    <SubScreen :title="isNew ? t('settings.profile.newFop') : t('settings.profile.fopProfile')" :back="{ name: 'more' }">
        <template #aside>
            <SideNote :text="t('settings.sideNotes.profile')" />
        </template>

        <LoadState :loading="loading && !isNew && !profile" :error="loadError" @retry="reload">
            <Dimmable>
                <p class="m-0 rounded-control border border-line bg-surface px-3.5 py-3 text-14 font-medium">{{ t('settings.profile.groupBanner') }}</p>
            </Dimmable>

            <template v-if="isNew">
                <RouterLink
                    v-if="can('import', 'backups')"
                    :to="{ name: 'backup-import' }"
                    class="flex items-center justify-between gap-3 rounded-card border border-line bg-surface p-4 text-ink no-underline"
                >
                    <span class="flex flex-col gap-0.5">
                        <span class="text-15 font-semibold">{{ t('settings.backups.import.title') }}</span>
                        <span class="text-13 text-ink-3">{{ t('settings.profile.restoreHint') }}</span>
                    </span>
                    <Icon name="chevronRight" :size="18" />
                </RouterLink>
                <section class="flex flex-col gap-3.5 rounded-card border border-line bg-surface p-4">
                    <h2 class="m-0 text-16 font-semibold">{{ t('settings.profile.taxpayer') }}</h2>
                    <ProfilePersonFields v-model="form" :errors="errors" />
                </section>
                <section class="flex flex-col gap-3.5 rounded-card border border-line bg-surface p-4">
                    <h2 class="m-0 text-16 font-semibold">{{ t('settings.profile.addressContacts') }}</h2>
                    <ProfileContactFields v-model="form" :errors="errors" />
                </section>
                <section class="flex flex-col gap-3.5 rounded-card border border-line bg-surface p-4">
                    <h2 class="m-0 text-16 font-semibold">{{ t('shell.nav.invoices') }}</h2>
                    <ProfileInvoiceFields v-model="form" />
                </section>
                <section class="flex flex-col gap-3.5 rounded-card border border-line bg-surface p-4">
                    <h2 class="m-0 text-16 font-semibold">{{ t('settings.profile.moneyFormat') }}</h2>
                    <ProfileFormatFields v-model="form" />
                </section>
                <p v-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>
            </template>

            <template v-else-if="profile">
                <AccordionGroup id="person" :title="t('settings.profile.taxpayer')" :editable="canEditIdentity" :saving="saving" :error="generalError" @open="open" @save="saveGroup('person')">
                    <ReadRow :label="t('settings.profile.fullNameShort')" :value="saved.full_name" />
                    <ReadRow :label="t('settings.profile.latinShort')" :value="saved.full_name_en || '—'" />
                    <ReadRow :label="saved.passport ? t('settings.profile.passport') : t('settings.profile.taxId')"><span class="num">{{ profile.tax_id_masked ?? '—' }}</span></ReadRow>
                    <ReadRow :label="t('settings.profile.registrationShort')"><span class="num">{{ saved.registered_at ? formatDate(saved.registered_at) : '—' }}</span></ReadRow>
                    <template #edit>
                        <ProfilePersonFields v-model="form" :masked-tax-id="profile.tax_id_masked ?? ''" :errors="errors" />
                    </template>
                </AccordionGroup>

                <AccordionGroup id="contacts" :title="t('settings.profile.addressContacts')" :editable="canEditIdentity" :saving="saving" :error="generalError" @open="open" @save="saveGroup('contacts')">
                    <ReadRow :label="t('core.common.address')" :value="saved.address || '—'" />
                    <ReadRow :label="t('settings.profile.englishShort')" :value="saved.address_en || '—'" />
                    <ReadRow label="Email" :value="saved.email || '—'" />
                    <ReadRow :label="t('settings.profile.phone')" :value="saved.phone || '—'" />
                    <template #edit>
                        <ProfileContactFields v-model="form" :errors="errors" />
                    </template>
                </AccordionGroup>

                <AccordionGroup id="invoices" :title="t('shell.nav.invoices')" :editable="canEditIdentity || canConfigure" :saving="saving" :error="generalError" @open="open" @save="saveGroup('invoices')">
                    <ReadRow :label="t('core.common.currency')" :value="saved.default_currency" />
                    <ReadRow :label="t('settings.profile.languageShort')" :value="DOCUMENT_LOCALES.find((locale) => locale.value === saved.locale)?.label ?? saved.locale" />
                    <ReadRow :label="t('settings.profile.signaturePdfs')">
                        <img v-if="signature" :src="apiUrl(path(`signatures/${signature.id}/download`))" :alt="t('settings.profile.signature')" class="h-8 max-w-32 object-contain">
                        <span v-else class="text-ink-3">{{ t('settings.profile.noSignature') }}</span>
                    </ReadRow>
                    <template #edit>
                        <ProfileInvoiceFields v-model="form" :disabled="!canEditIdentity" />
                        <div v-if="canConfigure" class="flex flex-col gap-1.5">
                            <span class="text-13 font-medium text-ink-2">{{ t('settings.profile.signaturePdfs') }}</span>
                            <div v-if="signature" class="flex items-center gap-3 rounded-control border border-line bg-surface p-3">
                                <img :src="apiUrl(path(`signatures/${signature.id}/download`))" :alt="t('settings.profile.signature')" class="h-10 max-w-32 object-contain">
                                <span class="min-w-0 flex-1 truncate text-14 text-ink-2">{{ signature.name }}</span>
                            </div>
                            <label class="flex min-h-14 cursor-pointer flex-col items-center justify-center gap-1 rounded-control border border-dashed border-switch-off text-15 font-medium text-accent">
                                <Icon :name="uploading ? 'spinner' : 'upload'" :size="20" :class="uploading ? 'animate-spin' : ''" />
                                {{ signature ? t('settings.profile.replaceSignaturePng') : t('settings.profile.uploadSignaturePng') }}
                                <input type="file" accept="image/png" class="sr-only" @change="uploadSignature">
                            </label>
                            <span v-if="signatureError" class="text-12 text-negative">{{ errorMessage(signatureError) }}</span>
                        </div>
                    </template>
                </AccordionGroup>

                <AccordionGroup id="formats" :title="t('settings.profile.moneyFormat')" :editable="canConfigure" :saving="saving" :error="generalError" @open="open" @save="saveGroup('formats')">
                    <ReadRow :label="t('settings.profile.inApp')"><span class="num">{{ sample(saved.display_format, '1840135.05') }} ₴</span></ReadRow>
                    <ReadRow :label="t('settings.profile.onInvoicesShort')"><span class="num">{{ sample(saved.invoice_format, '3892.50') }} {{ saved.default_currency }}</span></ReadRow>
                    <template #edit>
                        <ProfileFormatFields v-model="form" />
                    </template>
                </AccordionGroup>

                <Dimmable class="flex flex-col gap-2">
                    <SwitchCard
                        :model-value="profile.is_active"
                        :title="t('settings.profile.activeFop')"
                        :sub="t('settings.profile.inactiveHint')"
                        :disabled="!canEditIdentity || switching"
                        @update:model-value="saveActive({ is_active: $event })"
                    />
                    <DateField
                        v-if="!profile.is_active"
                        :model-value="profile.closed_at ?? ''"
                        :label="t('settings.profile.closingDate')"
                        :hint="t('settings.profile.closingDateHint')"
                        :max="today()"
                        :disabled="!canEditIdentity || switching"
                        @update:model-value="$event && saveActive({ closed_at: $event })"
                    />
                    <p v-if="switchError" class="m-0 text-14 text-negative" role="alert">{{ switchError }}</p>
                    <p v-if="!canEditIdentity" class="m-0 text-13 text-ink-3">{{ t('settings.profile.ownerEditsIdentity') }}</p>
                    <p class="m-0 text-13 leading-[1.45] text-ink-3">{{ t('core.accordion.oneAtATime') }}</p>
                </Dimmable>
            </template>
        </LoadState>

        <template v-if="isNew && canEditIdentity" #footer>
            <PrimaryButton :busy="creating" :disabled="!form.full_name.trim() || !form.tax_id.trim()" @click="create">
                {{ t('settings.profile.createFop') }}
            </PrimaryButton>
        </template>
    </SubScreen>
</template>
