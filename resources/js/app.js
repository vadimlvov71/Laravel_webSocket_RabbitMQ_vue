import './bootstrap';
import './echo';
import { createApp } from 'vue';
import App from './App.vue';

// Vuetify не подключаем: его reset-стили в CSS-слоях перекрывают утилиты Tailwind.
createApp(App).mount('#app');
