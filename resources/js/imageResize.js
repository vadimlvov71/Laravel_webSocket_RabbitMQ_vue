// Уменьшение картинки в браузере перед отправкой: на сервер уходят десятки КБ
// вместо мегабайт, и GD на сервере работает с маленьким файлом.
// Сервер всё равно проверяет и перекодирует файл: фронтенду не доверяем.

export const MAX_WIDTH = 320;
export const MAX_HEIGHT = 240;

// GIF не трогаем: canvas, как и GD, оставит только первый кадр, выгоды нет.
const RESIZABLE = ['image/jpeg', 'image/png'];

/** Размеры, вписанные в рамку с сохранением пропорций, без увеличения (как AttachmentStorage::fit). */
export function fit(width, height) {
    const scale = Math.min(1, MAX_WIDTH / width, MAX_HEIGHT / height);
    return [Math.max(1, Math.round(width * scale)), Math.max(1, Math.round(height * scale))];
}

/**
 * Возвращает уменьшенную копию картинки или исходный файл, если уменьшать
 * не нужно или не получилось (старый браузер, повреждённый файл).
 * В последнем случае картинку уменьшит сервер.
 */
export async function shrinkImage(file) {
    if (!RESIZABLE.includes(file.type) || typeof createImageBitmap !== 'function') return file;

    let bitmap;
    try {
        // from-image: фото с телефона поворачиваются по EXIF, иначе «лягут на бок»
        bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const [width, height] = fit(bitmap.width, bitmap.height);

        if (width === bitmap.width && height === bitmap.height) return file;

        const canvas = downscale(bitmap, width, height);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, file.type, 0.85));
        if (!blob || blob.size >= file.size) return file;

        return new File([blob], file.name, { type: file.type, lastModified: file.lastModified });
    } catch {
        return file;
    } finally {
        bitmap?.close();
    }
}

// Уменьшение в несколько шагов по половине: при сжатии в 10+ раз за один шаг
// Firefox и Safari дают «лесенку» на краях (imageSmoothingQuality они игнорируют).
function downscale(source, width, height) {
    let current = source;
    let w = source.width;
    let h = source.height;

    do {
        w = Math.max(width, Math.round(w / 2));
        h = Math.max(height, Math.round(h / 2));
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d');
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(current, 0, 0, w, h);
        current = canvas;
    } while (w > width || h > height);

    return current;
}
