<script setup>
import { ref, reactive, onMounted, nextTick } from 'vue';
import axios from 'axios';

const props = defineProps({ replyTo: { type: Object, default: null } });
const emit = defineEmits(['created']);

const blank = () => ({ user_name: '', email: '', home_page: '', text: '' });
const form = reactive(blank());
const errors = reactive({});
const captchaToken = ref('');
const captchaEl = ref(null);
const textarea = ref(null);
const submitting = ref(false);
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

    if (!captchaToken.value) errors.captcha = 'Please confirm that you are not a robot.';

    return Object.keys(errors).length === 0;
}

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
    if (!validate()) return;
    submitting.value = true;

    try {
        await axios.post('/comments', {
            ...form,
            home_page: form.home_page || null,
            parent_id: props.replyTo?.id ?? null,
            captcha: captchaToken.value,
        });
        Object.assign(form, blank());
        clearErrors();
        emit('created');
    } catch (e) {
        if (e.response?.status === 422) {
            const server = e.response.data.errors ?? {};
            Object.keys(server).forEach((k) => (errors[k] = server[k][0]));
        } else if (e.response?.status === 429) {
            errors.form = 'Too many requests. Please wait a minute.';
        } else {
            errors.form = 'Something went wrong. Please try again.';
        }
    } finally {
        submitting.value = false;
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

        <div>
            <div ref="captchaEl"></div>
            <span v-if="errors.captcha" class="text-xs text-red-600">{{ errors.captcha }}</span>
        </div>

        <p v-if="errors.form" role="alert" class="text-sm text-red-600">{{ errors.form }}</p>
        <button type="submit" :disabled="submitting"
                class="rounded-md bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:cursor-not-allowed disabled:opacity-50">
            {{ submitting ? 'Sending…' : 'Submit' }}
        </button>
    </form>
</template>