import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { type Component, createRenderer, defineComponent, h, KeepAlive, nextTick, shallowRef } from 'vue';
import { api, dataVersion } from '@/api/client';
import type { Paginated } from '@/api/types';
import { useLoader } from '@/composables/useLoader';
import { usePagedList } from '@/composables/usePagedList';

/** A node of the in-memory renderer, which mounts components without a DOM. */
interface TestNode {
    text?: string;
    children: TestNode[];
    parent: TestNode | null;
}

const node = (text?: string): TestNode => ({ text, children: [], parent: null });

function detach(child: TestNode): void {
    if (child.parent) {
        child.parent.children.splice(child.parent.children.indexOf(child), 1);
        child.parent = null;
    }
}

const { createApp } = createRenderer<TestNode, TestNode>({
    createElement: () => node(),
    createText: (text) => node(text),
    createComment: (text) => node(text),
    setText: (target, text) => {
        target.text = text;
    },
    setElementText: (target, text) => {
        target.children = [{ ...node(text), parent: target }];
    },
    insert: (child, parent, anchor) => {
        detach(child);

        const index = anchor ? parent.children.indexOf(anchor) : -1;

        parent.children.splice(index >= 0 ? index : parent.children.length, 0, child);
        child.parent = parent;
    },
    remove: detach,
    parentNode: (target) => target.parent,
    nextSibling: (target) => (target.parent ? target.parent.children[target.parent.children.indexOf(target) + 1] ?? null : null),
    patchProp: () => {},
});

/**
 * Mount a screen kept alive (KeepAlive) beside another one, and give a way to leave it and come back.
 */
async function keptAlive(screen: Component): Promise<() => Promise<void>> {
    const other = defineComponent({ name: 'Other', setup: () => () => h('i') });
    const shown = shallowRef<Component>(screen);

    createApp(defineComponent({ setup: () => () => h(KeepAlive, { include: ['Screen'] }, [h(shown.value)]) })).mount(node());
    await nextTick();

    return async () => {
        shown.value = other;
        await nextTick();
        shown.value = screen;
        await nextTick();
    };
}

describe('dataVersion', () => {
    beforeEach(() => {
        vi.stubGlobal('document', { cookie: '' });
        vi.stubGlobal('fetch', vi.fn(async (_url: string, init: RequestInit) => new Response(
            init.method === 'POST' && String(_url).endsWith('invoices') ? '{"message":"The number is taken."}' : '{"data":{}}',
            { status: init.method === 'POST' && String(_url).endsWith('invoices') ? 422 : 200 },
        )));
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('moves on a write the API accepts, not on a read, a preview, a proposal or a refused write', async () => {
        const before = dataVersion.value;

        await api.get('profiles/1/invoices');
        await api.post('profiles/1/payments/preview', {});
        await api.post('profiles/1/transfers/propose', {});
        await api.post('profiles/1/invoices', {}).catch(() => null);

        expect(dataVersion.value).toBe(before);

        await api.post('profiles/1/tax-payments', {});
        await api.patch('profiles/1/invoices/7', {});

        expect(dataVersion.value).toBe(before + 2);
    });
});

describe('a screen kept alive', () => {
    it('shows its data again as it was left, and loads again only after a write', async () => {
        const fetcher = vi.fn(async () => 'data');
        const back = await keptAlive(defineComponent({
            name: 'Screen',
            setup: () => {
                useLoader(fetcher);

                return () => h('span');
            },
        }));

        await back();
        expect(fetcher).toHaveBeenCalledTimes(1);

        dataVersion.value++;
        await back();
        expect(fetcher).toHaveBeenCalledTimes(2);
    });

    it('keeps its list as it was left, and reads the shown pages again only after a write', async () => {
        const fetchPage = vi.fn(async (): Promise<Paginated<{ id: number }>> => ({
            data: [{ id: 1 }],
            meta: { total: 1, links: { next: null } },
        } as Paginated<{ id: number }>));
        const back = await keptAlive(defineComponent({
            name: 'Screen',
            setup: () => {
                usePagedList(fetchPage);

                return () => h('span');
            },
        }));

        await back();
        expect(fetchPage).toHaveBeenCalledTimes(1);

        dataVersion.value++;
        await back();
        expect(fetchPage).toHaveBeenCalledTimes(2);
    });
});
