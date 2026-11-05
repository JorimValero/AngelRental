(function () {
  const menu = document.querySelector('[data-notification-menu]');
  if (!menu) {
    return;
  }

  const endpoint = menu.dataset.endpoint;
  const endpointUrl = new URL(endpoint, document.baseURI);
  const csrfToken = menu.dataset.csrfToken;
  const summary = menu.querySelector('summary');
  const countElement = menu.querySelector('[data-notification-count]');
  const list = menu.querySelector('[data-notification-list]');
  let loading = false;
  let refreshAfterLoad = false;

  function showState(message) {
    const state = document.createElement('p');
    state.className = 'notification-state';
    state.textContent = message;
    list.replaceChildren(state);
  }

  function renderCount(count) {
    const unreadCount = Number.isFinite(count) ? Math.max(0, count) : 0;
    countElement.hidden = unreadCount === 0;
    countElement.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
    summary.setAttribute(
      'aria-label',
      unreadCount > 0 ? 'Notifications, ' + unreadCount + ' unread' : 'Notifications'
    );
  }

  function renderNotifications(notifications) {
    list.replaceChildren();
    if (notifications.length === 0) {
      showState('You’re all caught up. New updates will appear here.');
      return;
    }

    notifications.forEach(function (notification) {
      const link = document.createElement('a');
      const title = document.createElement('strong');
      const message = document.createElement('span');
      const time = document.createElement('time');
      const notificationId = Number(notification.id);
      const updatedAt = new Date(notification.updated_at);

      link.className = 'notification-item';
      if (notification.is_unread) {
        link.classList.add('is-unread');
      }
      link.href = new URL(notification.href, endpointUrl).href;
      title.className = 'notification-item-title';
      title.textContent = notification.product_name || 'Reservation update';
      message.className = 'notification-item-message';
      message.textContent = notification.message || 'Your reservation has been updated.';
      time.className = 'notification-item-time';
      time.dateTime = notification.updated_at;
      time.textContent = Number.isNaN(updatedAt.getTime())
        ? ''
        : new Intl.DateTimeFormat(undefined, {
          dateStyle: 'medium',
          timeStyle: 'short'
        }).format(updatedAt);

      link.append(title, message, time);
      list.appendChild(link);
    });
  }

  function loadNotifications() {
    if (loading) {
      refreshAfterLoad = true;
      return Promise.resolve();
    }
    loading = true;
    return fetch(endpoint, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    })
      .then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok) {
            throw new Error(data.error || 'Notifications could not be loaded (' + response.status + ').');
          }
          return data;
        });
      })
      .then(function (data) {
        if (!Array.isArray(data.notifications) || !Number.isFinite(Number(data.unread_count))) {
          throw new Error('The notification response was invalid.');
        }
        renderCount(Number(data.unread_count));
        renderNotifications(data.notifications);
      })
      .catch(function (error) {
        showState(error.message || 'Notifications could not be loaded. Please try again.');
        console.error(error);
      })
      .finally(function () {
        loading = false;
        if (refreshAfterLoad) {
          refreshAfterLoad = false;
          loadNotifications();
        }
      });
  }

  function markNotificationsRead() {
    const body = new URLSearchParams({ csrf_token: csrfToken });
    fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
      },
      body: body.toString()
    })
      .then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok) {
            throw new Error(data.error || 'Notifications could not be marked as read (' + response.status + ').');
          }
          return loadNotifications();
        });
      })
      .catch(function (error) {
        showState(error.message || 'Notifications could not be marked as read. Please try again.');
        console.error(error);
      });
  }

  menu.addEventListener('toggle', function () {
    if (menu.open) {
      markNotificationsRead();
    }
  });
  loadNotifications();
  window.setInterval(function () {
    if (!document.hidden) {
      loadNotifications();
    }
  }, 30000);
})();
