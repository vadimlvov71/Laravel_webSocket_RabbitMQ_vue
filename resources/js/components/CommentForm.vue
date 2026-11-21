<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import axios from 'axios';
import { shrinkImage } from '../imageResize';
import { fade } from '../transitions';

const props = defineProps({ replyTo: { type: Object, default: null } });
const emit = defineEmits(['created']);

const blank = () => ({ user_name: '', email: '', home_page: '', text: '' });
const form = reactive(blank());
const errors = reactive({});
const captchaToken = ref('');
const captchaEl = ref(null);
const textarea = ref(null);
const submitting = ref(false);
const file = ref(null);

// Превью того, что реально уйдёт на сервер: для картинки это уже уменьшенная копия.
const previewUrl = ref('');
const previewText = ref('');
const PREVIEW_TEXT_LINES = 6;

watch(file, async (f) => {
    // object URL держит файл в памяти, пока его не отпустить
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = '';
    previewText.value = '';
    if (!f) return;

    if (f.type.startsWith('image/')) {
        previewUrl.value = URL.createObjectURL(f);
    } else {
        // начала файла достаточно для превью; читать все 100 КБ незачем
        const head = await f.slice(0, 2048).text();
        if (file.value === f) previewText.value = head.split(/\r?\n/).slice(0, PREVIEW_TEXT_LINES).join('\n');
    }
});

const showPreview = computed(() => Boolean(
    file.value && !processing.value && !errors.attachment && (previewUrl.value || previewText.value),
));

// кнопка удаления исчезает вместе с файлом, поэтому фокус переводим на выбор файла
function removeFile() {
    clearFile();
    fileInput.value?.focus();
}

onBeforeUnmount(() => {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
});
const fileInput = ref(null);

// Те же ограничения, что в StoreCommentRequest и CommentAttachment на сервере.
const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif'];
const ALLOWED_EXT = /\.(jpe?g|png|gif|txt)$/i;
const TEXT_MAX_KB = 100;
// Исходник в форме может быть больше серверного лимита: уйдёт он уже уменьшенным.
const SOURCE_MAX_MB = 20;
const IMAGE_MAX_MB = 5; // то, что реально отправляется (лимит сервера)
const originalSize = ref(0);
const processing = ref(false);

// Прогресс загрузки показываем только для крупных файлов: маленький (а после
// уменьшения на фронте картинка весит десятки КБ) уходит мгновенно, и полоса
// лишь мелькнула бы. Крупными бывают GIF и картинки, которые браузер не уменьшил.
const PROGRESS_MIN_KB = 300;
const uploadPercent = ref(null); // null: полосы нет
const uploadPhase = computed(() => {
    if (uploadPercent.value === null) return null;
    // файл ушёл, сервер проверяет капчу, обрабатывает файл и пишет в БД
    return uploadPercent.value < 100 ? 'Uploading…' : 'Processing…';
});
let pickId = 0;
let widgetId = null;

// ---------- reCAPTCHA ----------
function loadRecaptcha() {
    return new Promise((resolve) => {
        if (window.grecaptcha?.render) return resolve(window.grecaptcha);
        window.__onRecaptcha = () => resolve(window.grecaptcha);
        const s = document.createElement('script');
        s.src = 'https://www.google.com/recaptcha/api.js?onload=__onRecaptcha&render=explicit';
        s.async = true;
        document.head.appendChild(s);
    });
}

onMounted(async () => {
    const g = await loadRecaptcha();
    widgetId = g.render(captchaEl.value, {
        sitekey: window.__config?.recaptchaSiteKey,
        callback: (token) => { captchaToken.value = token; delete errors.captcha; },
        'expired-callback': () => { captchaToken.value = ''; },
    });
});

// ---------- клиентская валидация ----------
function markupError(text) {
    const allowed = ['a', 'code', 'i', 'strong'];
    const stack = [];
    const re = /<(\/?)([a-z]+)[^<>]*>/gi;
    let m;
    while ((m = re.exec(text))) {
        const name = m[2].toLowerCase();
        if (!allowed.includes(name)) continue;
        if (m[1]) {
            if (stack.pop() !== name) return `Closing tag </${name}> is in the wrong place.`;
        } else {
            stack.push(name);
        }
    }
    return stack.length ? `Tag <${stack.at(-1)}> is not closed.` : null;
}

function clearErrors() {
    Object.keys(errors).forEach((k) => delete errors[k]);
}

function validate() {
    clearErrors();

    if (!form.user_name) errors.user_name = 'User Name is required.';
    else if (!/^[A-Za-z0-9_-]+$/.test(form.user_name)) errors.user_name = 'Only Latin letters, digits, underscore and hyphen are allowed.';
    else if ((form.user_name.match(/[A-Za-z]/g) ?? []).length < 2) errors.user_name = 'User Name must include at least two Latin letters.';

    if (!form.email) errors.email = 'E-mail is required.';
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) errors.email = 'Enter a valid e-mail.';

    if (form.home_page) {
        try {
            const u = new URL(form.home_page);
            if (!['http:', 'https:'].includes(u.protocol)) throw new Error();
        } catch {
            errors.home_page = 'Enter a valid URL (http:// or https://).';
        }
    }

    if (!form.text.trim()) errors.text = 'Text is required.';
    else {
        const err = markupError(form.text);
        if (err) errors.text = err;
    }

    const fileError = attachmentError(file.value);
    if (fileError) errors.attachment = fileError;

    if (!captchaToken.value) errors.captcha = 'Please confirm that you are not a robot.';

    return Object.keys(errors).length === 0;
}

// ---------- вложение ----------
// maxImageMb: для выбранного исходника SOURCE_MAX_MB, для отправляемого файла IMAGE_MAX_MB.
function attachmentError(f, maxImageMb = IMAGE_MAX_MB) {
    if (!f) return null;
    const isText = f.type === 'text/plain' || /\.txt$/i.test(f.name);
    if (!ALLOWED_EXT.test(f.name) || (!isText && !IMAGE_TYPES.includes(f.type))) {
        return 'Only JPG, GIF, PNG images and TXT files are allowed.';
    }
    if (isText && f.size > TEXT_MAX_KB * 1024) return `A text file must not be larger than ${TEXT_MAX_KB} KB.`;
    if (!isText && f.size > maxImageMb * 1024 * 1024) return `An image must not be larger than ${maxImageMb} MB.`;
    return null;
}

async function onFileChange(e) {
    const picked = e.target.files[0] ?? null;
    const id = ++pickId; // пока шла обработка, могли выбрать другой файл
    delete errors.attachment;
    file.value = picked;
    originalSize.value = picked?.size ?? 0;

    // исходник проверяем до декодирования: 100-мегабайтный файл не стоит разжимать
    const err = attachmentError(picked, SOURCE_MAX_MB);
    if (err) {
        errors.attachment = err;
        return;
    }
    if (!picked?.type.startsWith('image/')) return;

    processing.value = true;
    const result = await shrinkImage(picked);
    if (id !== pickId) return;
    processing.value = false;
    file.value = result;
    const sendErr = attachmentError(result);
    if (sendErr) errors.attachment = sendErr;
}

function clearFile() {
    pickId++;
    processing.value = false;
    originalSize.value = 0;
    file.value = null;
    delete errors.attachment;
    if (fileInput.value) fileInput.value.value = '';
}

const formatSize = (bytes) => (bytes < 1024 * 1024 ? `${Math.ceil(bytes / 1024)} KB` : `${(bytes / 1048576).toFixed(1)} MB`);

// ---------- кнопки тегов ----------
function wrap(tag) {
    const el = textarea.value;
    const start = el.selectionStart;
    const end = el.selectionEnd;
    const selected = form.text.slice(start, end);
    const open = tag === 'a' ? '<a href="" title="">' : `<${tag}>`;
    form.text = form.text.slice(0, start) + open + selected + `</${tag}>` + form.text.slice(end);
    nextTick(() => {
        el.focus();
        const pos = start + open.length + selected.length;
        el.setSelectionRange(pos, pos);
    });
}

// ---------- отправка ----------
async function submit() {
    // Enter в поле отправляет форму, даже когда кнопка заблокирована
    if (processing.value || submitting.value || !validate()) return;
    submitting.value = true;

    try {
        // multipart: JSON не умеет передавать файл
        const data = new FormData();
        Object.entries(form).forEach(([k, v]) => data.append(k, v));
        if (props.replyTo) data.append('parent_id', props.replyTo.id);
        if (file.value) data.append('attachment', file.value);
        data.append('captcha', captchaToken.value);

        const trackProgress = file.value && file.value.size > PROGRESS_MIN_KB * 1024;
        if (trackProgress) uploadPercent.value = 0;

        const { data: created } = await axios.post('/comments', data, {
            // при повторе после 419 (bootstrap.js) отсчёт просто начнётся заново
            onUploadProgress: trackProgress
                ? (e) => { if (e.total) uploadPercent.value = Math.min(100, Math.round((e.loaded * 100) / e.total)); }
                : undefined,
        });
        Object.assign(form, blank());
        clearFile();
        clearErrors();
        emit('created', created.id);
    } catch (e) {
        if (e.response?.status === 422) {
            const server = e.response.data.errors ?? {};
            Object.keys(server).forEach((k) => (errors[k] = server[k][0]));
        } else if (e.response?.status === 413) {
            errors.attachment = 'The file is too large.';
        } else if (e.response?.status === 429) {
            errors.form = 'Too many requests. Please wait a minute.';
        } else {
            errors.form = 'Something went wrong. Please try again.';
        }
    } finally {
        submitting.value = false;
        uploadPercent.value = null;
        captchaToken.value = '';
        if (widgetId !== null) window.grecaptcha.reset(widgetId);
    }
}

const input = 'mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500';
</script>

<template>
    <!-- Заголовок и закрытие даёт BaseModal, поэтому форма без собственной рамки -->
    <form class="space-y-4" novalidate @submit.prevent="submit">

        <div class="grid gap-4 sm:grid-cols-3">
            <label class="block text-sm font-medium">
                User Name *
                <input v-model="form.user_name" type="text" maxlength="64" :class="input" />
                <span v-if="errors.user_name" class="text-xs text-red-600">{{ errors.user_name }}</span>
            </label>
            <label class="block text-sm font-medium">
                E-mail *
                <input v-model="form.email" type="email" :class="input" />
                <span v-if="errors.email" class="text-xs text-red-600">{{ errors.email }}</span>
            </label>
            <label class="block text-sm font-medium">
                Home Page
                <input v-model="form.home_page" type="url" placeholder="https://" :class="input" />
                <span v-if="errors.home_page" class="text-xs text-red-600">{{ errors.home_page }}</span>
            </label>
        </div>

        <div>
            <div class="mb-1 flex flex-wrap items-center gap-2 text-sm">
                <span class="font-medium">Text *</span>
                <button type="button" class="rounded border px-2 py-0.5 hover:bg-gray-100" @click="wrap('a')">&lt;a&gt;</button>
                <button type="button" class="rounded border px-2 py-0.5 font-mono hover:bg-gray-100" @click="wrap('code')">&lt;code&gt;</button>
                <button type="button" class="rounded border px-2 py-0.5 italic hover:bg-gray-100" @click="wrap('i')">&lt;i&gt;</button>
                <button type="button" class="rounded border px-2 py-0.5 font-bold hover:bg-gray-100" @click="wrap('strong')">&lt;strong&gt;</button>
            </div>
            <textarea ref="textarea" v-model="form.text" rows="5" :class="input"></textarea>
            <span v-if="errors.text" class="text-xs text-red-600">{{ errors.text }}</span>
        </div>

        <div class="text-sm">
            <span class="font-medium">Attachment</span>
            <span class="text-gray-500"> (JPG, PNG up to {{ SOURCE_MAX_MB }} MB or GIF up to {{ IMAGE_MAX_MB }} MB, resized to 320×240; or TXT up to {{ TEXT_MAX_KB }} KB)</span>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <label class="cursor-pointer rounded-md border border-gray-300 bg-white px-3 py-1.5 font-medium hover:bg-gray-50 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-blue-600">
                    Choose file
                    <input ref="fileInput" type="file" class="sr-only"
                           accept=".jpg,.jpeg,.png,.gif,.txt,image/jpeg,image/png,image/gif,text/plain"
                           @change="onFileChange" />
                </label>
                <template v-if="file">
                    <span class="min-w-0 truncate text-gray-700">
                        {{ file.name }} ·
                        <template v-if="processing">resizing…</template>
                        <template v-else-if="file.size < originalSize">{{ formatSize(originalSize) }} → {{ formatSize(file.size) }}</template>
                        <template v-else>{{ formatSize(file.size) }}</template>
                    </span>
                    <!-- пока превью нет (идёт уменьшение или ошибка), удалить можно отсюда -->
                    <button v-if="!showPreview" type="button" aria-label="Remove attachment"
                            class="rounded px-1 text-lg leading-none text-gray-500 hover:text-red-600 focus-visible:outline-2 focus-visible:outline-blue-600"
                            @click="removeFile">&times;</button>
                </template>
                <span v-else class="text-gray-500">No file chosen</span>
            </div>
            <span v-if="errors.attachment" class="text-xs text-red-600">{{ errors.attachment }}</span>

            <Transition v-bind="fade">
                <div v-if="showPreview" class="mt-2 flex items-start gap-3">
                    <img v-if="previewUrl" :src="previewUrl" :alt="`Preview of ${file.name}`"
                         class="block h-auto max-h-32 w-auto min-w-0 max-w-full rounded-md border border-gray-200 bg-gray-50" />
                    <pre v-else class="max-h-32 min-w-0 flex-1 overflow-hidden whitespace-pre-wrap break-words rounded-md border border-gray-200 bg-gray-50 p-2 font-mono text-xs text-gray-700">{{ previewText }}</pre>
                    <button type="button" :aria-label="`Remove attachment ${file.name}`"
                            class="inline-flex shrink-0 items-center gap-1 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:border-red-300 hover:bg-red-50 hover:text-red-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                            @click="removeFile">
                        <span aria-hidden="true" class="text-base leading-none">&times;</span> Remove
                    </button>
                </div>
            </Transition>
        </div>

        <div>
            <div ref="captchaEl"></div>
            <span v-if="errors.captcha" class="text-xs text-red-600">{{ errors.captcha }}</span>
        </div>

        <p v-if="errors.form" role="alert" class="text-sm text-red-600">{{ errors.form }}</p>

        <div v-if="uploadPercent !== null" class="space-y-1">
            <div class="flex justify-between text-xs text-gray-600">
                <!-- объявляется только смена этапа, а не каждый процент -->
                <span role="status">{{ uploadPhase }}</span>
                <span aria-hidden="true">{{ uploadPercent }}%</span>
            </div>
            <div role="progressbar" aria-label="File upload" aria-valuemin="0" aria-valuemax="100"
                 :aria-valuenow="uploadPercent" :aria-valuetext="`${uploadPhase} ${uploadPercent}%`"
                 class="h-2 overflow-hidden rounded-full bg-gray-200">
                <div class="h-full rounded-full bg-blue-600 motion-safe:transition-[width] motion-safe:duration-200"
                     :class="{ 'motion-safe:animate-pulse': uploadPercent === 100 }"
                     :style="{ width: `${uploadPercent}%` }"></div>
            </div>
        </div>
        <button type="submit" :disabled="submitting || processing"
                class="rounded-md bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:cursor-not-allowed disabled:opacity-50">
            {{ submitting ? (uploadPhase ?? 'Sending…') : 'Submit' }}
        </button>
    </form>
</template>