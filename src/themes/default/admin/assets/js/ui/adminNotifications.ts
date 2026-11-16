// Polls the admin notification center and surfaces new entries as toasts
// plus an unread badge on the sidebar bell. Runs only when the bell element
// is present (staff without the notification permission never see it).

const SEEN_KEY = 'fossbilling_admin_notifications_seen_id';
const TOASTED_KEY = 'fossbilling_admin_notifications_toasted_id';
const POLL_INTERVAL_MS = 20000;
const PAGE_SIZE = 10;
const MAX_POPUPS = 4;
const POPUP_TTL_MS = 6000;
const POPUP_FADE_MS = 400;

function readId(key: string): number | null {
  try {
    const raw = window.localStorage.getItem(key);
    if (raw === null) {
      return null;
    }
    const parsed = Number.parseInt(raw, 10);
    return Number.isNaN(parsed) ? null : parsed;
  } catch {
    return null;
  }
}

function storeId(key: string, id: number): void {
  try {
    window.localStorage.setItem(key, String(id));
  } catch {
    // Storage may be unavailable (private mode); polling still works in-memory.
  }
}

function popupContainer(): HTMLElement {
  let container = document.getElementById('admin-notification-popups');
  if (!container) {
    container = document.createElement('div');
    container.id = 'admin-notification-popups';
    document.body.appendChild(container);
  }
  return container;
}

function showPopup(message: string, href: string | null): void {
  const container = popupContainer();
  while (container.children.length >= MAX_POPUPS) {
    container.firstElementChild?.remove();
  }

  const popup = document.createElement(href === null ? 'div' : 'a');
  popup.className = 'admin-notification-popup';
  if (href !== null) {
    popup.setAttribute('href', href);
  }
  const text = document.createElement('span');
  text.className = 'admin-notification-popup-text';
  text.textContent = message;
  popup.appendChild(text);
  container.appendChild(popup);

  window.setTimeout(() => popup.classList.add('is-hiding'), POPUP_TTL_MS);
  window.setTimeout(() => popup.remove(), POPUP_TTL_MS + POPUP_FADE_MS + 50);
}

export default function initAdminNotifications() {
  const bell = document.getElementById('admin-notification-bell');
  const badge = document.getElementById('admin-notification-badge');
  const api = window.FOSSBilling?.api;
  if (!bell || !badge || !api?.admin) {
    return;
  }

  // seenId drives the badge (cleared by opening /notification); toastedId
  // drives toast deduplication across polls and reloads.
  let seenId = readId(SEEN_KEY);
  let toastedId = readId(TOASTED_KEY);
  let newestKnownId = Math.max(seenId ?? 0, toastedId ?? 0);
  let timer = null;

  const renderBadge = (count: number) => {
    if (count > 0) {
      badge.textContent = count > 99 ? '99+' : String(count);
      badge.classList.remove('d-none');
    } else {
      badge.classList.add('d-none');
    }
  };

  const poll = () => {
    api.admin.get(
      'notification/get_list',
      { page: 1, per_page: PAGE_SIZE, sort: 'id', direction: 'DESC' },
      (result: { list?: Array<Record<string, unknown>> } | null) => {
        const list = Array.isArray(result?.list) ? result.list : [];
        const items = list
          .map((item) => ({ id: Number(item?.id) || 0, message: String(item?.meta_value ?? '') }))
          .filter((item) => item.id > 0 && item.message !== '');
        if (items.length === 0) {
          return;
        }

        const newestId = Math.max(...items.map((item) => item.id));
        newestKnownId = Math.max(newestKnownId, newestId);

        if (seenId === null && toastedId === null) {
          // First poll on this browser: record the baseline silently so old
          // entries do not spam toasts on every login.
          seenId = newestId;
          toastedId = newestId;
          storeId(SEEN_KEY, seenId);
          storeId(TOASTED_KEY, toastedId);
          renderBadge(0);
          return;
        }

        const baseline = seenId ?? toastedId ?? 0;
        renderBadge(items.filter((item) => item.id > baseline).length);

        const toastBaseline = toastedId ?? 0;
        const fresh = items.filter((item) => item.id > toastBaseline).sort((a, b) => a.id - b.id);
        if (fresh.length > 0) {
          toastedId = newestId;
          storeId(TOASTED_KEY, toastedId);
          const href = bell.getAttribute('href');
          fresh.slice(-MAX_POPUPS).forEach((item) => {
            showPopup(item.message, href);
          });
          if (fresh.length > MAX_POPUPS) {
            showPopup(`+${fresh.length - MAX_POPUPS} more notification(s)`, href);
          }
        }
      },
      () => {
        // Notification API unavailable (permission/module off): stay quiet.
      },
      false,
    );
  };

  bell.addEventListener('click', () => {
    // Opening the center marks everything fetched so far as seen.
    seenId = newestKnownId;
    storeId(SEEN_KEY, seenId);
    renderBadge(0);
  });

  poll();
  timer = window.setInterval(poll, POLL_INTERVAL_MS);
  window.addEventListener('pagehide', () => window.clearInterval(timer));
}
