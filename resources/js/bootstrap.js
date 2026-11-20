import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// 419: сессия истекла (вкладка долго была открыта или перезапускался Redis).
// Любой GET через web-middleware выдаёт новую сессию и cookie XSRF-TOKEN,
// axios подставит его в повторный запрос сам. Повторяем один раз.
// Капча при 419 не расходуется: CSRF-middleware отклоняет запрос до валидации.
axios.interceptors.response.use(null, async (error) => {
    const config = error.config;

    if (error.response?.status !== 419 || !config || config._csrfRetried) {
        return Promise.reject(error);
    }

    config._csrfRetried = true;
    await axios.head('/');

    return axios(config);
});
