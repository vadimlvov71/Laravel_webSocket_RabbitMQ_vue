// id комментариев, отправленных из этого браузера. Учётных записей нет,
// поэтому «вам ответили» определяем так: parent_id нового ответа есть в этом списке.
// localStorage может быть недоступен (приватный режим, запрет cookies):
// тогда просто нет особого уведомления, остальное работает.

const KEY = 'comments:mine';
const LIMIT = 200;

function read() {
    try {
        const ids = JSON.parse(localStorage.getItem(KEY) ?? '[]');
        return Array.isArray(ids) ? ids : [];
    } catch {
        return [];
    }
}

export function rememberMine(id) {
    try {
        const ids = read().filter((x) => x !== id);
        ids.push(id);
        localStorage.setItem(KEY, JSON.stringify(ids.slice(-LIMIT)));
    } catch {
        // нет хранилища: функция необязательная
    }
}

export function isMine(id) {
    return id != null && read().includes(id);
}
