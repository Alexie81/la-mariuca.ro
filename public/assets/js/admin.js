(() => {
  'use strict';

  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const password = document.querySelector('[data-admin-password]');
  const passwordToggle = document.querySelector('[data-admin-password-toggle]');

  if (password && passwordToggle) {
    passwordToggle.addEventListener('click', () => {
      const reveal = password.type === 'password';
      password.type = reveal ? 'text' : 'password';
      passwordToggle.setAttribute('aria-pressed', reveal ? 'true' : 'false');
      passwordToggle.setAttribute('aria-label', reveal ? 'Ascunde parola' : 'Arată parola');
      password.focus({ preventScroll: true });
    });
  }

  const loginForm = document.querySelector('[data-admin-login-form]');
  if (loginForm) {
    loginForm.addEventListener('submit', () => {
      const submit = loginForm.querySelector('[data-admin-login-submit]');
      if (!submit || !loginForm.checkValidity()) return;
      submit.classList.add('is-loading');
      const label = submit.querySelector('span');
      if (label) label.textContent = 'Se verifică…';
    });
  }

  const sidebar = document.querySelector('[data-admin-sidebar]');
  const menuToggle = document.querySelector('[data-admin-menu-toggle]');
  const sidebarCollapse = document.querySelector('[data-admin-sidebar-collapse]');
  const backdrop = document.querySelector('[data-admin-menu-close]');
  const sidebarStorageKey = 'la-mariuca-admin-sidebar-collapsed';
  const desktopSidebar = window.matchMedia('(min-width: 1001px)');

  const setDesktopSidebar = (collapsed, persist = true) => {
    document.body.classList.toggle('admin-sidebar-collapsed', collapsed);
    if (sidebar) {
      sidebar.removeAttribute('aria-hidden');
      sidebar.inert = false;
    }
    if (sidebarCollapse) {
      sidebarCollapse.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      sidebarCollapse.setAttribute('aria-label', collapsed ? 'Extinde bara laterală' : 'Restrânge bara laterală');
    }
    if (menuToggle && desktopSidebar.matches) {
      menuToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      menuToggle.setAttribute('aria-label', collapsed ? 'Extinde bara laterală' : 'Restrânge bara laterală');
    }
    if (persist) {
      try { window.localStorage.setItem(sidebarStorageKey, collapsed ? '1' : '0'); } catch (_) {}
    }
  };

  const setMobileMenu = (open) => {
    if (!sidebar || !menuToggle || !backdrop) return;
    sidebar.classList.toggle('is-open', open);
    sidebar.setAttribute('aria-hidden', open ? 'false' : 'true');
    sidebar.inert = !open;
    backdrop.classList.toggle('is-visible', open);
    menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    menuToggle.setAttribute('aria-label', open ? 'Închide meniul' : 'Deschide meniul');
    if (sidebarCollapse) {
      sidebarCollapse.setAttribute('aria-expanded', open ? 'true' : 'false');
      sidebarCollapse.setAttribute('aria-label', 'Închide meniul');
    }
    document.body.style.overflow = open ? 'hidden' : '';
  };

  if (desktopSidebar.matches) {
    let collapsed = false;
    try { collapsed = window.localStorage.getItem(sidebarStorageKey) === '1'; } catch (_) {}
    setDesktopSidebar(collapsed, false);
  } else {
    setMobileMenu(false);
  }

  menuToggle?.addEventListener('click', () => {
    if (desktopSidebar.matches) {
      setDesktopSidebar(!document.body.classList.contains('admin-sidebar-collapsed'));
      return;
    }
    setMobileMenu(!sidebar?.classList.contains('is-open'));
  });
  sidebarCollapse?.addEventListener('click', () => {
    if (desktopSidebar.matches) {
      setDesktopSidebar(!document.body.classList.contains('admin-sidebar-collapsed'));
      return;
    }
    setMobileMenu(false);
  });
  backdrop?.addEventListener('click', () => setMobileMenu(false));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !desktopSidebar.matches) setMobileMenu(false);
  });
  window.addEventListener('resize', () => {
    if (desktopSidebar.matches) {
      setMobileMenu(false);
      let collapsed = false;
      try { collapsed = window.localStorage.getItem(sidebarStorageKey) === '1'; } catch (_) {}
      setDesktopSidebar(collapsed, false);
    } else {
      document.body.classList.remove('admin-sidebar-collapsed');
      setMobileMenu(false);
    }
  });
  sidebar?.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => {
    if (!desktopSidebar.matches) setMobileMenu(false);
  }));

  const clocks = document.querySelectorAll('[data-admin-clock]');
  if (clocks.length) {
    const updateClock = () => {
      const value = new Intl.DateTimeFormat('ro-RO', {
        timeZone: 'Europe/Bucharest', hour: '2-digit', minute: '2-digit', second: '2-digit'
      }).format(new Date());
      clocks.forEach((clock) => { clock.textContent = value; });
    };
    updateClock();
    window.setInterval(updateClock, 1000);
  }

  const browserNotificationButton = document.querySelector('[data-admin-browser-notifications]');
  if (browserNotificationButton) {
    const label = browserNotificationButton.querySelector('span');
    const configured = browserNotificationButton.dataset.pushConfigured === '1';
    const csrf = browserNotificationButton.dataset.pushCsrf || '';
    let registration = null;
    let activeSubscription = null;
    const setButtonState = (state) => {
      browserNotificationButton.classList.toggle('is-active', state === 'active');
      browserNotificationButton.classList.toggle('is-loading', state === 'loading');
      if (!label) return;
      label.textContent = state === 'active' ? 'Notificări active' : (state === 'loading' ? 'Se conectează…' : (state === 'blocked' ? 'Notificări blocate' : (state === 'unsupported' ? 'Indisponibil' : (state === 'unconfigured' ? 'Configurare necesară' : 'Activează notificările'))));
    };

    const base64UrlToBytes = (value) => {
      const padding = '='.repeat((4 - value.length % 4) % 4);
      const binary = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
      return Uint8Array.from(binary, (character) => character.charCodeAt(0));
    };
    const browserName = () => {
      const brands = navigator.userAgentData?.brands || [];
      const brand = brands.map((item) => item.brand).find((item) => !/not.?a.?brand/i.test(item)) || '';
      if (/edge|edg/i.test(brand) || /Edg\//.test(navigator.userAgent)) return 'Edge';
      if (/opera|opr/i.test(brand) || /OPR\//.test(navigator.userAgent)) return 'Opera';
      if (/chrome|chromium/i.test(brand) || /Chrome\//.test(navigator.userAgent) || /CriOS\//.test(navigator.userAgent)) return 'Chrome';
      if (/Firefox\//.test(navigator.userAgent) || /FxiOS\//.test(navigator.userAgent)) return 'Firefox';
      if (/Safari\//.test(navigator.userAgent)) return 'Safari';
      return 'Browser';
    };
    const normalizedPlatform = (value) => {
      const platform = String(value || '');
      if (/win/i.test(platform)) return 'Windows';
      if (/android/i.test(platform)) return 'Android';
      if (/iphone|ios/i.test(platform)) return 'iPhone';
      if (/ipad/i.test(platform)) return 'iPad';
      if (/mac/i.test(platform)) return 'macOS';
      if (/linux/i.test(platform)) return 'Linux';
      return platform || 'Dispozitiv';
    };
    const androidModelFromAgent = () => {
      const match = navigator.userAgent.match(/Android[^;)]*;\s*([^;)]+?)(?:\s+Build\/[^;)]*)?[;)]/i);
      return match ? match[1].replace(/\s+Build\/.*/i, '').trim() : '';
    };
    const collectDeviceInfo = async () => {
      let platform = navigator.userAgentData?.platform || navigator.platform || '';
      let model = '';
      let mobile = Boolean(navigator.userAgentData?.mobile) || /Android|Mobile|iPhone|iPad|iPod/i.test(navigator.userAgent);
      if (navigator.userAgentData?.getHighEntropyValues) {
        try {
          const hints = await navigator.userAgentData.getHighEntropyValues(['model', 'platform', 'platformVersion', 'mobile']);
          platform = hints.platform || platform;
          model = String(hints.model || '').trim();
          mobile = Boolean(hints.mobile) || mobile;
        } catch (_) {}
      }
      if (!model && /Android/i.test(navigator.userAgent)) model = androidModelFromAgent();
      if (!model && /iPhone/i.test(navigator.userAgent)) model = 'iPhone';
      if (!model && /iPad/i.test(navigator.userAgent)) model = 'iPad';
      return {
        kind: mobile ? 'phone' : 'desktop',
        platform: normalizedPlatform(platform),
        model: model.slice(0, 55),
        browser: browserName(),
      };
    };
    const updateDeviceCard = (device) => {
      if (!device?.id) return;
      const card = document.querySelector(`[data-push-device-id="${device.id}"]`);
      if (!card) return;
      const label = card.querySelector('strong');
      const icon = card.querySelector('[data-push-device-icon]');
      if (label) label.textContent = device.device_label || label.textContent;
      if (icon) {
        icon.innerHTML = device.device_kind === 'mobile'
          ? '<svg viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="3"/><path d="M10 18h4"/></svg>'
          : '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4"/></svg>';
      }
    };
    const postJson = async (url, body = {}) => {
      const response = await fetch(url, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
        body: JSON.stringify(body),
      });
      const data = await response.json().catch(() => ({ ok: false, message: 'Răspuns invalid de la server.' }));
      if (!response.ok || !data.ok) throw new Error(data.message || 'Operațiunea nu a reușit.');
      return data;
    };
    const registerSubscription = async (subscription) => {
      const serialized = subscription.toJSON();
      serialized.contentEncoding = (PushManager.supportedContentEncodings || ['aes128gcm'])[0];
      const result = await postJson(browserNotificationButton.dataset.pushSubscribeUrl, {
        subscription: serialized,
        device: await collectDeviceInfo(),
      });
      updateDeviceCard(result.device);
      return result;
    };
    const initializePush = async () => {
      if (!configured) {
        setButtonState('unconfigured');
        browserNotificationButton.disabled = true;
        return;
      }
      if (!window.isSecureContext || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        setButtonState('unsupported');
        browserNotificationButton.disabled = true;
        return;
      }
      registration = await navigator.serviceWorker.register(browserNotificationButton.dataset.pushServiceWorker, { scope: browserNotificationButton.dataset.pushScope });
      activeSubscription = await registration.pushManager.getSubscription();
      if (activeSubscription) await registerSubscription(activeSubscription);
      if (Notification.permission === 'denied') setButtonState('blocked');
      else setButtonState(activeSubscription ? 'active' : 'idle');
    };

    if (!configured) {
      setButtonState('unconfigured');
      browserNotificationButton.disabled = true;
    } else if (!window.isSecureContext || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
      setButtonState('unsupported');
      browserNotificationButton.disabled = true;
    } else {
      initializePush().catch(() => setButtonState('idle'));
      browserNotificationButton.addEventListener('click', async () => {
        try {
          setButtonState('loading');
          if (!registration) await initializePush();
          activeSubscription = await registration.pushManager.getSubscription();
          if (activeSubscription) {
            const endpoint = activeSubscription.endpoint;
            await activeSubscription.unsubscribe();
            await postJson(browserNotificationButton.dataset.pushUnsubscribeUrl, { endpoint });
            activeSubscription = null;
            setButtonState('idle');
            return;
          }
          const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
          if (permission !== 'granted') {
            setButtonState(permission === 'denied' ? 'blocked' : 'idle');
            return;
          }
          activeSubscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: base64UrlToBytes(browserNotificationButton.dataset.pushPublicKey || ''),
          });
          await registerSubscription(activeSubscription);
          setButtonState('active');
        } catch (error) {
          setButtonState(Notification.permission === 'denied' ? 'blocked' : 'idle');
          window.alert(error?.message || 'Notificările nu au putut fi activate.');
        }
      });
    }

    document.querySelector('[data-admin-push-test]')?.addEventListener('click', async (event) => {
      const button = event.currentTarget;
      const oldText = button.textContent;
      button.disabled = true;
      button.textContent = 'Se trimite…';
      try {
        await postJson(browserNotificationButton.dataset.pushTestUrl);
        button.textContent = 'Trimisă ✓';
      } catch (error) {
        button.textContent = 'Nu s-a trimis';
        window.alert(error?.message || 'Notificarea de test nu a putut fi trimisă.');
      } finally {
        window.setTimeout(() => { button.disabled = false; button.textContent = oldText; }, 1800);
      }
    });
  }

  const inboxList = document.querySelector('.admin-inbox-list');
  if (inboxList) {
    const updateInboxStats = (data) => {
      const setText = (selector, value) => {
        const element = document.querySelector(selector);
        if (element) element.textContent = numberFormatter.format(Number(value || 0));
      };
      setText('[data-inbox-unread-count]', data.unread_total);
      setText('[data-inbox-total-count]', data.counts?.toate);
      Object.entries(data.counts || {}).forEach(([key, value]) => setText(`[data-inbox-filter-count="${key}"]`, value));
      const visible = document.querySelector('[data-inbox-visible-count]');
      if (visible) visible.textContent = `${data.total} ${Number(data.total) === 1 ? 'eveniment' : 'evenimente'}`;
    };

    const animateReflow = (item, delay = 0) => new Promise((resolve) => {
      const siblings = [...inboxList.querySelectorAll('[data-admin-notification-item]')].filter((entry) => entry !== item);
      const before = new Map(siblings.map((entry) => [entry, entry.getBoundingClientRect()]));
      const finish = () => {
        item.remove();
        siblings.forEach((entry) => {
          const previous = before.get(entry);
          if (!previous || !entry.isConnected) return;
          const current = entry.getBoundingClientRect();
          const deltaY = previous.top - current.top;
          if (Math.abs(deltaY) < 1 || reducedMotion) return;
          entry.animate(
            [{ transform: `translateY(${deltaY}px)` }, { transform: 'translateY(0)' }],
            { duration: 520, easing: 'cubic-bezier(.2,.82,.2,1)' }
          );
        });
        resolve();
      };
      if (reducedMotion) {
        window.setTimeout(finish, delay);
        return;
      }
      window.setTimeout(() => {
        item.classList.add('is-being-deleted');
        const animation = item.animate([
          { opacity: 1, transform: 'translateX(0) scale(1)', filter: 'blur(0)' },
          { opacity: .55, offset: .55, transform: 'translateX(22px) scale(.985)', filter: 'blur(0)' },
          { opacity: 0, transform: 'translateX(105px) scale(.96)', filter: 'blur(3px)' },
        ], { duration: 430, easing: 'cubic-bezier(.55,0,.25,1)', fill: 'forwards' });
        animation.finished.then(finish).catch(finish);
      }, delay);
    });

    const sendDelete = async (form) => {
      const response = await fetch(form.action, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form),
      });
      const data = await response.json().catch(() => ({ ok: false }));
      if (!response.ok || !data.ok) throw new Error(data.message || 'Notificarea nu a putut fi ștearsă.');
      return data;
    };

    inboxList.querySelectorAll('[data-admin-notification-delete]').forEach((form) => {
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (form.dataset.busy === '1') return;
        form.dataset.busy = '1';
        const button = form.querySelector('button');
        const item = form.closest('[data-admin-notification-item]');
        button?.classList.add('is-loading');
        try {
          const data = await sendDelete(form);
          updateInboxStats(data);
          if (item) await animateReflow(item);
          if (!inboxList.querySelector('[data-admin-notification-item]')) {
            window.setTimeout(() => { window.location.href = data.redirect; }, reducedMotion ? 0 : 260);
          }
        } catch (error) {
          form.dataset.busy = '0';
          button?.classList.remove('is-loading');
          window.alert(error?.message || 'Notificarea nu a putut fi ștearsă.');
        }
      });
    });

    const deleteAllForm = document.querySelector('[data-admin-notification-delete-all]');
    deleteAllForm?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = deleteAllForm.querySelector('button');
      const label = button?.querySelector('span');
      if (!button || button.disabled) return;
      if (deleteAllForm.dataset.confirmed !== '1') {
        deleteAllForm.dataset.confirmed = '1';
        button.classList.add('is-armed');
        if (label) label.textContent = 'Confirmă ștergerea';
        window.clearTimeout(deleteAllForm._confirmTimer);
        deleteAllForm._confirmTimer = window.setTimeout(() => {
          deleteAllForm.dataset.confirmed = '0';
          button.classList.remove('is-armed');
          if (label) label.textContent = button.dataset.defaultLabel || 'Șterge toate';
        }, 3500);
        return;
      }
      button.disabled = true;
      button.classList.add('is-loading');
      if (label) label.textContent = 'Se șterg…';
      try {
        const data = await sendDelete(deleteAllForm);
        updateInboxStats(data);
        const items = [...inboxList.querySelectorAll('[data-admin-notification-item]')];
        await Promise.all(items.map((item, index) => animateReflow(item, index * 42)));
        window.setTimeout(() => { window.location.href = data.redirect; }, reducedMotion ? 0 : 260);
      } catch (error) {
        button.disabled = false;
        button.classList.remove('is-loading', 'is-armed');
        if (label) label.textContent = button.dataset.defaultLabel || 'Șterge toate';
        window.alert(error?.message || 'Notificările nu au putut fi șterse.');
      }
    });
  }

  const numberFormatter = new Intl.NumberFormat('ro-RO');
  const moneyFormatter = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const animateCount = (element) => {
    const target = Math.max(0, Number.parseInt(element.dataset.countUp || '0', 10));
    if (reducedMotion || target === 0) {
      element.textContent = numberFormatter.format(target);
      return;
    }
    const start = performance.now();
    const duration = Math.min(1200, 620 + target * 15);
    const frame = (time) => {
      const progress = Math.min(1, (time - start) / duration);
      const eased = 1 - Math.pow(1 - progress, 3);
      element.textContent = numberFormatter.format(Math.round(target * eased));
      if (progress < 1) requestAnimationFrame(frame);
    };
    requestAnimationFrame(frame);
  };

  const animateMoney = (element) => {
    const target = Math.max(0, Number.parseInt(element.dataset.moneyCountUp || '0', 10));
    const render = (value) => { element.textContent = `${moneyFormatter.format(value / 100)} lei`; };
    if (reducedMotion || target === 0) {
      render(target);
      return;
    }
    const start = performance.now();
    const duration = 1050;
    const frame = (time) => {
      const progress = Math.min(1, (time - start) / duration);
      render(Math.round(target * (1 - Math.pow(1 - progress, 3))));
      if (progress < 1) requestAnimationFrame(frame);
    };
    requestAnimationFrame(frame);
  };

  const revealElement = (element) => {
    if (element.matches('[data-count-up]')) animateCount(element);
    if (element.matches('[data-money-count-up]')) animateMoney(element);
    if (element.matches('[data-progress-bar]')) element.classList.add('is-visible');
    if (element.matches('[data-chart-bar]')) element.classList.add('is-visible');
  };
  const animated = document.querySelectorAll('[data-count-up],[data-money-count-up],[data-progress-bar],[data-chart-bar]');
  if ('IntersectionObserver' in window && !reducedMotion) {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        revealElement(entry.target);
        observer.unobserve(entry.target);
      });
    }, { threshold: .3 });
    animated.forEach((element) => observer.observe(element));
  } else {
    animated.forEach(revealElement);
  }

  const chartScroller = document.querySelector('[data-admin-chart-scroll]');
  document.querySelectorAll('[data-chart-scroll]').forEach((button) => {
    button.addEventListener('click', () => {
      if (!chartScroller) return;
      const direction = Number.parseInt(button.dataset.chartScroll || '1', 10);
      chartScroller.scrollBy({ left: direction * Math.max(260, chartScroller.clientWidth * .72), behavior: reducedMotion ? 'auto' : 'smooth' });
    });
  });

  document.addEventListener('click', (event) => {
    document.querySelectorAll('.admin-period__custom[open]').forEach((details) => {
      if (!details.contains(event.target)) details.removeAttribute('open');
    });
  });

  const productDeleteDialog = document.querySelector('[data-admin-product-delete-dialog]');
  const productDeleteName = productDeleteDialog?.querySelector('[data-admin-product-delete-name]');
  const productDeleteConfirm = productDeleteDialog?.querySelector('[data-admin-product-delete-confirm]');
  let pendingProductDeleteForm = null;

  const closeProductDeleteDialog = () => {
    if (!productDeleteDialog?.open) return;
    productDeleteDialog.classList.remove('is-visible');
    window.setTimeout(() => productDeleteDialog.close(), reducedMotion ? 0 : 180);
  };

  document.querySelectorAll('[data-admin-product-delete-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      pendingProductDeleteForm = form;
      if (productDeleteName) productDeleteName.textContent = form.dataset.productName || 'Produsul';
      if (!productDeleteDialog || typeof productDeleteDialog.showModal !== 'function') {
        if (window.confirm(`Ștergi produsul „${form.dataset.productName || ''}”?`)) form.submit();
        return;
      }
      productDeleteDialog.showModal();
      requestAnimationFrame(() => productDeleteDialog.classList.add('is-visible'));
    });
  });

  productDeleteDialog?.querySelectorAll('[data-admin-product-delete-cancel]').forEach((button) => {
    button.addEventListener('click', closeProductDeleteDialog);
  });
  productDeleteDialog?.addEventListener('click', (event) => {
    if (event.target === productDeleteDialog) closeProductDeleteDialog();
  });
  productDeleteDialog?.addEventListener('cancel', (event) => {
    event.preventDefault();
    closeProductDeleteDialog();
  });
  productDeleteDialog?.addEventListener('close', () => {
    productDeleteDialog.classList.remove('is-visible');
    pendingProductDeleteForm = null;
  });
  productDeleteConfirm?.addEventListener('click', () => {
    if (!pendingProductDeleteForm) return;
    productDeleteConfirm.disabled = true;
    productDeleteConfirm.textContent = 'Se șterge…';
    pendingProductDeleteForm.submit();
  });

  document.querySelectorAll('[data-admin-toast]').forEach((toast) => {
    const remove = () => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(-8px)';
      window.setTimeout(() => toast.remove(), reducedMotion ? 0 : 230);
    };
    toast.querySelector('[data-admin-toast-close]')?.addEventListener('click', remove);
    window.setTimeout(remove, 5500);
  });
})();
