import { afterEach, describe, expect, it, vi } from 'vitest';
import { api } from '@/api/client';
import { uploadInPieces } from '@/api/uploads';

/**
 * A file goes up in the pieces the server asks for, each at the offset where the server says the upload ends, telling
 * the share sent.
 */
describe('uploadInPieces', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('sends the pieces the server asks for, at the offsets it tells', async () => {
        const sent: { path: string; offset: string | null; bytes: number }[] = [];
        vi.spyOn(api, 'post').mockImplementation(async (path: string, body?: unknown) => {
            if (!(body instanceof FormData)) {
                return { data: { id: 'up1', piece: 4 }, message: 'OK' };
            }

            const piece = body.get('piece') as Blob;
            sent.push({ path, offset: body.get('offset') as string | null, bytes: piece.size });

            return { data: { size: Number(body.get('offset')) + piece.size }, message: 'OK' };
        });
        const shares: number[] = [];

        const id = await uploadInPieces('organization/backups/uploads', new File(['0123456789'], 'backup.zip'), (share) => shares.push(share));

        expect(id).toBe('up1');
        expect(api.post).toHaveBeenNthCalledWith(1, 'organization/backups/uploads', { size: 10 });
        expect(sent).toEqual([
            { path: 'organization/backups/uploads/up1', offset: '0', bytes: 4 },
            { path: 'organization/backups/uploads/up1', offset: '4', bytes: 4 },
            { path: 'organization/backups/uploads/up1', offset: '8', bytes: 2 },
        ]);
        expect(shares).toEqual([0.4, 0.8, 1]);
    });
});
