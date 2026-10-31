import { api } from '@/api/client';
import type { ApiResponse } from '@/api/types';

/**
 * Upload a file in pieces, as a server takes small requests (PHP's upload limit, a proxy's, Cloud Run's 32 MB):
 * `POST {base}` starts it with the file's bytes (a file larger than the server takes is refused there) and tells the
 * upload's id and the bytes of a piece, then `POST {base}/{id}` adds each piece at its offset, the next starting where
 * the server says the upload ends. Tells the share sent, and gets the upload's id.
 */
export async function uploadInPieces(base: string, file: File, progress: (share: number) => void): Promise<string> {
    const { data } = await api.post<ApiResponse<{ id: string; piece: number }>>(base, { size: file.size });
    let offset = 0;

    while (offset < file.size) {
        const body = new FormData();
        body.append('piece', file.slice(offset, offset + data.piece), 'piece');
        body.append('offset', String(offset));

        const { data: sent } = await api.post<ApiResponse<{ size: number }>>(`${base}/${data.id}`, body);
        offset = sent.size;
        progress(Math.min(1, offset / file.size));
    }

    return data.id;
}
