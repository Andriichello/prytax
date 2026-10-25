<script setup lang="ts">
import { computed, ref } from 'vue';
import type { Account } from '@/api/types';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import { t } from '@/i18n';
import { accountLabel } from '@/utils/accounts';

/**
 * The accounts taxes are paid from (design.md §8.4, «Сплата податків»), each with its route to the budget: `UAH →
 * бюджет`, or from a foreign-currency one `USD → продаж банком → {the same bank's UAH ФОП account} → бюджет`. One is
 * «Основний», which a new payment starts with; ⋯ makes another one primary or takes it off the list, and «+ Додати»
 * lists the other accounts taxes may be paid from (active, in UAH or a ФОП account in another currency).
 */
const { accounts } = defineProps<{ accounts: Account[] }>();

const ids = defineModel<number[]>('ids', { required: true });
const primary = defineModel<number | null>('primary', { required: true });

const pays = (account: Account): boolean => account.is_active && (account.currency === 'UAH' || account.kind === 'fop_bank');

const listed = computed(() => ids.value
    .map((id) => accounts.find((account) => account.id === id))
    .filter((account): account is Account => account !== undefined));
const addable = computed(() => accounts.filter((account) => pays(account) && !ids.value.includes(account.id)));

/** The ФОП's UAH account at the bank of a foreign-currency one, which the bank sells the currency into. */
function uahAccountOf(account: Account): Account | null {
    return accounts.find((item) => item.is_active && item.kind === 'fop_bank' && item.currency === 'UAH'
        && !!account.bank_name && item.bank_name === account.bank_name) ?? null;
}

function routeOf(account: Account): string[] {
    if (account.currency === 'UAH') {
        return ['UAH', t('settings.taxes.routeBudget')];
    }

    const uah = uahAccountOf(account);

    return [
        account.currency,
        t('settings.taxes.routeBankSells'),
        uah ? accountLabel(uah.name, uah.currency) : t('settings.taxes.routeUahAccount'),
        t('settings.taxes.routeBudget'),
    ];
}

const addOpen = ref(false);
const menuFor = ref<Account | null>(null);
const menuOpen = computed({
    get: () => menuFor.value !== null,
    set: (isOpen: boolean) => {
        if (!isOpen) {
            menuFor.value = null;
        }
    },
});

function add(account: Account): void {
    ids.value = [...ids.value, account.id];
    primary.value ??= account.id;
    addOpen.value = false;
}

function makePrimary(account: Account): void {
    primary.value = account.id;
    menuFor.value = null;
}

/** Take the account off the list; the primary one passes to the first one left. */
function remove(account: Account): void {
    ids.value = ids.value.filter((id) => id !== account.id);

    if (primary.value === account.id) {
        primary.value = ids.value[0] ?? null;
    }

    menuFor.value = null;
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="flex items-center justify-between">
            <span class="text-14 text-ink-2">{{ t('settings.taxes.paymentAccounts') }}</span>
            <button type="button" class="flex min-h-11 items-center gap-1 text-15 font-medium text-accent" @click="addOpen = true">
                <Icon name="plus" :size="18" />
                {{ t('core.common.add') }}
            </button>
        </div>
        <div v-if="listed.length" class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
            <div v-for="account in listed" :key="account.id" class="flex flex-col gap-2 py-3">
                <div class="flex items-center gap-2">
                    <span class="min-w-0 flex-1 truncate text-16 font-medium">{{ account.name }}</span>
                    <span class="num text-13 text-ink-2">{{ account.currency }}</span>
                    <span v-if="account.id === primary" class="rounded-badge bg-accent-soft px-2 py-0.5 text-12 font-semibold text-accent-hover">
                        {{ t('accounting.taxPayment.primary') }}
                    </span>
                    <button
                        type="button"
                        :aria-label="t('core.common.moreActions')"
                        class="-mr-2 flex size-10 items-center justify-center text-ink-2"
                        @click="menuFor = account"
                    >
                        <Icon name="more" :size="20" />
                    </button>
                </div>
                <div class="flex flex-wrap items-center gap-1.5 text-13 text-ink-2">
                    <template v-for="(step, index) in routeOf(account)" :key="index">
                        <Icon v-if="index" name="chevronRight" :size="14" class="text-ink-3" />
                        <span class="rounded-md bg-muted px-1.5 py-0.5">{{ step }}</span>
                    </template>
                </div>
            </div>
        </div>
        <p v-else class="m-0 rounded-card border border-line bg-surface p-4 text-14 text-ink-2">{{ t('settings.taxes.noPaymentAccounts') }}</p>
        <p class="m-0 text-13 leading-[1.45] text-ink-3">{{ t('settings.taxes.paymentAccountsHint') }}</p>

        <BottomSheet v-model:open="addOpen" :title="t('settings.taxes.addPaymentAccount')">
            <div v-if="addable.length" class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                <button
                    v-for="account in addable"
                    :key="account.id"
                    type="button"
                    class="flex min-h-13 items-center justify-between gap-3 text-left text-15"
                    @click="add(account)"
                >
                    {{ accountLabel(account.name, account.currency) }}
                    <Icon name="plus" :size="18" class="text-accent" />
                </button>
            </div>
            <p v-else class="m-0 text-15 text-ink-2">{{ t('settings.taxes.allAccountsListed') }}</p>
        </BottomSheet>

        <BottomSheet v-model:open="menuOpen" :title="menuFor ? accountLabel(menuFor.name, menuFor.currency) : ''">
            <div v-if="menuFor" class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                <button v-if="menuFor.id !== primary" type="button" class="flex min-h-13 items-center gap-3 text-left text-15" @click="makePrimary(menuFor)">
                    <Icon name="check" :size="20" /> {{ t('settings.taxes.makePrimary') }}
                </button>
                <button type="button" class="flex min-h-13 items-center gap-3 text-left text-15 text-negative" @click="remove(menuFor)">
                    <Icon name="close" :size="20" /> {{ t('settings.taxes.removeAccount') }}
                </button>
            </div>
        </BottomSheet>
    </div>
</template>
