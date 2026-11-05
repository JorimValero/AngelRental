(function () {
  const noticeTypes = {
    success: { title: 'Completed', icon: '✓' },
    error: { title: 'Action required', icon: '!' },
    warning: { title: 'Please note', icon: '!' },
    info: { title: 'Information', icon: 'i' }
  };
  let region = null;
  let validationAnchor = null;
  let confirmationDialog = null;
  const pendingConfirmations = new WeakSet();
  const confirmedForms = new WeakSet();

  function getRegion() {
    if (region && region.isConnected) {
      return region;
    }

    region = document.createElement('div');
    region.className = 'system-notice-region';
    region.setAttribute('aria-label', 'Notifications');
    document.body.appendChild(region);
    return region;
  }

  function dismissDismissibleNotice() {
    if (!region || (
      !region.classList.contains('system-notice-validation-region')
      && !region.classList.contains('system-notice-auth-region')
      && !region.classList.contains('system-notice-workflow-region')
    )) {
      return;
    }

    region.remove();
    region = null;
    validationAnchor = null;
  }

  function positionValidationNotice() {
    if (!region || !region.classList.contains('system-notice-validation-region')
      || !validationAnchor || !validationAnchor.isConnected) {
      return;
    }

    const field = validationAnchor.getBoundingClientRect();
    const noticeWidth = region.offsetWidth;
    const noticeHeight = region.offsetHeight;
    const left = Math.max(12, Math.min(
      field.left + (field.width - noticeWidth) / 2,
      window.innerWidth - noticeWidth - 12
    ));
    let top = field.bottom + 8;

    if (top + noticeHeight > window.innerHeight - 12) {
      top = Math.max(12, field.top - noticeHeight - 8);
    }

    region.style.left = left + 'px';
    region.style.top = top + 'px';
    region.style.setProperty(
      '--notice-arrow-left',
      Math.max(12, Math.min(field.left + field.width / 2 - left, noticeWidth - 12)) + 'px'
    );
  }

  function showNotice(type, titleText, messageText, source, anchor, workflow) {
    const regionElement = getRegion();
    regionElement.replaceChildren();
    const expandedWorkflowNotice = Boolean(workflow)
      || Boolean(source && source.classList.contains('sign-in-error'));
    const compactError = !expandedWorkflowNotice && Boolean(source && (
      source.classList.contains('form-error')
      || source.classList.contains('staff-workflow-error')
    ));
    regionElement.classList.toggle('system-notice-validation-region', Boolean(anchor));
    regionElement.classList.toggle('system-notice-auth-region', Boolean(compactError));
    regionElement.classList.toggle('system-notice-workflow-region', expandedWorkflowNotice);
    regionElement.style.left = '';
    regionElement.style.top = '';
    regionElement.style.removeProperty('--notice-arrow-left');
    validationAnchor = anchor || null;
    const details = noticeTypes[type];
    const notice = document.createElement('div');
    const heading = document.createElement('strong');
    const message = document.createElement('div');
    const icon = document.createElement('span');
    const closeButton = document.createElement('button');

    if (source) {
      Array.from(source.attributes).forEach(function (attribute) {
        notice.setAttribute(attribute.name, attribute.value);
      });
    } else {
      notice.setAttribute('role', 'alert');
      notice.setAttribute('aria-live', 'assertive');
    }

    notice.classList.add('system-notice', 'system-notice-' + type);
    if (anchor) {
      notice.classList.add('system-notice-validation');
    } else if (compactError) {
      notice.classList.add('system-notice-auth');
    }
    notice.setAttribute('aria-atomic', 'true');
    notice.dataset.systemNotice = 'true';

    icon.className = 'system-notice-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = details.icon;

    heading.className = 'system-notice-title';
    heading.textContent = titleText || details.title;

    message.className = 'system-notice-message';
    if (source) {
      const sourceTitle = source.querySelector('.form-error-title');
      Array.from(source.childNodes).forEach(function (child) {
        if (child !== sourceTitle) {
          message.appendChild(child);
        }
      });
    } else {
      message.textContent = messageText;
    }

    closeButton.className = 'system-notice-dismiss';
    closeButton.type = 'button';
    closeButton.setAttribute('aria-label', 'Dismiss notification');
    closeButton.textContent = '×';
    closeButton.addEventListener('click', function () {
      notice.remove();
      if (regionElement.childElementCount === 0) {
        regionElement.remove();
        region = null;
      }
    });

    if (anchor) {
      notice.append(icon, message);
    } else if (compactError) {
      notice.append(icon, heading, message);
    } else {
      notice.append(icon, heading, message, closeButton);
    }
    if (source) {
      source.replaceWith(notice);
    }
    regionElement.appendChild(notice);
    if (anchor) {
      positionValidationNotice();
    }
  }

  document.addEventListener('pointerdown', dismissDismissibleNotice, true);
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      dismissDismissibleNotice();
    }
  });
  window.addEventListener('resize', positionValidationNotice);
  window.addEventListener('scroll', positionValidationNotice, true);

  function getConfirmationDialog() {
    if (confirmationDialog) {
      return confirmationDialog;
    }

    const dialog = document.createElement('dialog');
    const card = document.createElement('div');
    const icon = document.createElement('span');
    const title = document.createElement('h2');
    const message = document.createElement('p');
    const actions = document.createElement('div');
    const cancelButton = document.createElement('button');
    const confirmButton = document.createElement('button');

    dialog.className = 'system-confirm-dialog';
    dialog.setAttribute('aria-labelledby', 'system-confirm-title');
    dialog.setAttribute('aria-describedby', 'system-confirm-message');

    card.className = 'system-confirm-card';
    icon.className = 'system-confirm-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = '!';

    title.className = 'system-confirm-title';
    title.id = 'system-confirm-title';
    title.textContent = 'Confirm cancellation';

    message.className = 'system-confirm-message';
    message.id = 'system-confirm-message';

    actions.className = 'system-confirm-actions';
    cancelButton.className = 'system-confirm-cancel';
    cancelButton.type = 'button';
    cancelButton.textContent = 'Keep booking';
    confirmButton.className = 'system-confirm-submit';
    confirmButton.type = 'button';
    confirmButton.textContent = 'Cancel booking';

    actions.append(cancelButton, confirmButton);
    card.append(icon, title, message, actions);
    dialog.appendChild(card);
    document.body.appendChild(dialog);

    confirmationDialog = {
      element: dialog,
      message: message,
      cancelButton: cancelButton,
      confirmButton: confirmButton,
      resolve: null
    };

    function finish(confirmed) {
      if (!confirmationDialog.resolve) {
        return;
      }

      const resolve = confirmationDialog.resolve;
      confirmationDialog.resolve = null;
      dialog.close();
      resolve(confirmed);
    }

    cancelButton.addEventListener('click', function () {
      finish(false);
    });
    confirmButton.addEventListener('click', function () {
      finish(true);
    });
    dialog.addEventListener('cancel', function (event) {
      event.preventDefault();
      finish(false);
    });

    return confirmationDialog;
  }

  function requestConfirmation(message) {
    const dialog = getConfirmationDialog();
    dialog.message.textContent = message;

    return new Promise(function (resolve) {
      dialog.resolve = resolve;
      dialog.element.showModal();
      dialog.cancelButton.focus();
    });
  }

  Array.from(
    document.querySelectorAll('.store-notice, .form-error, .form-success')
  ).filter(function (notice) {
    return !notice.hidden && !notice.closest('[hidden]');
  }).forEach(function (source) {
    const type = source.classList.contains('store-notice-error') || source.classList.contains('form-error')
      ? 'error'
      : source.classList.contains('store-notice-warning')
        ? 'warning'
        : source.classList.contains('store-notice-info')
          ? 'info'
          : 'success';
    const title = source.querySelector('.form-error-title');
    showNotice(
      type,
      title ? title.textContent : '',
      '',
      source,
      null,
      source.classList.contains('staff-workflow-early-pickup')
        || source.classList.contains('sign-in-error')
    );
  });

  document.querySelectorAll('[data-auth-validation]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      const formType = form.dataset.authValidation;
      const fields = Array.from(form.querySelectorAll('input, select, textarea'));
      const invalidField = fields.find(function (field) {
        return !field.checkValidity()
          || (field.required && field.type !== 'password' && field.value.trim() === '');
      });

      if (invalidField) {
        event.preventDefault();
        invalidField.focus();
        const isMissing = invalidField.validity.valueMissing
          || (invalidField.required && invalidField.type !== 'password' && invalidField.value.trim() === '');
        showNotice(
          'error',
          'Action Required',
          isMissing
            ? 'Please fill out this field.'
            : 'Please check this field and try again.',
          null,
          invalidField
        );
        return;
      }

      if (formType === 'login') {
        const identifier = form.elements.identifier.value.trim();
        const isEmail = identifier.length <= 254
          && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(identifier);
        const isUsername = /^[a-zA-Z0-9][a-zA-Z0-9_.-]{2,29}$/.test(identifier);

        if (!isEmail && !isUsername) {
          event.preventDefault();
          form.elements.identifier.focus();
          showNotice(
            'error',
            'Action Required',
            'Enter a valid email address or username.',
            null,
            form.elements.identifier
          );
        }
        return;
      }

      if (
        formType === 'register'
        && form.elements.password.value !== form.elements.password_confirmation.value
      ) {
        event.preventDefault();
        form.elements.password_confirmation.focus();
        showNotice(
          'error',
          'Action Required',
          'The passwords do not match.',
          null,
          form.elements.password_confirmation
        );
      }
    });
  });

  document.querySelectorAll('form[data-workflow-validation]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (event.submitter && event.submitter.formNoValidate) {
        return;
      }

      const fields = Array.from(form.querySelectorAll('input, select, textarea'));
      const invalidField = fields.find(function (field) {
        return !field.disabled && !field.checkValidity();
      });

      if (!invalidField) {
        return;
      }

      event.preventDefault();
      invalidField.focus();
      const message = invalidField.type === 'checkbox'
        ? 'Please check this box if you want to proceed.'
        : invalidField.tagName === 'SELECT'
          ? 'Please choose an option to continue.'
          : invalidField.validity.valueMissing
            ? 'Please complete this field.'
            : 'Please check this field and try again.';
      showNotice('error', 'Action Required', message, null, invalidField, false);
    });
  });

  document.querySelectorAll('form[data-confirm-message]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (confirmedForms.has(form)) {
        confirmedForms.delete(form);
        return;
      }

      event.preventDefault();
      if (pendingConfirmations.has(form)) {
        return;
      }

      pendingConfirmations.add(form);
      const submitter = event.submitter;
      requestConfirmation(form.dataset.confirmMessage).then(function (confirmed) {
        pendingConfirmations.delete(form);
        if (!confirmed) {
          return;
        }

        confirmedForms.add(form);
        if (submitter && submitter.form === form) {
          form.requestSubmit(submitter);
        } else {
          form.requestSubmit();
        }
      });
    });
  });
})();
