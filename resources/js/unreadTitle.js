// Счётчик новых комментариев в заголовке вкладки, пока вкладка в фоне:
// «(2) Comments». Сбрасывается, когда пользователь вернулся на вкладку.

let baseTitle = document.title;
let unread = 0;

function render() {
    document.title = unread > 0 ? `(${unread}) ${baseTitle}` : baseTitle;
}

function onVisibilityChange() {
    if (document.visibilityState === 'visible' && unread > 0) {
        unread = 0;
        render();
    }
}

export function countUnread() {
    if (document.visibilityState === 'visible') return;
    unread += 1;
    render();
}

export function startUnreadTitle() {
    baseTitle = document.title;
    document.addEventListener('visibilitychange', onVisibilityChange);
}

export function stopUnreadTitle() {
    document.removeEventListener('visibilitychange', onVisibilityChange);
    unread = 0;
    render();
}
