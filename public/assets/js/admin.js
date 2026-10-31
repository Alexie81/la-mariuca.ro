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

  const productNameRequests = new WeakMap();
  const productSkuRequests = new WeakMap();
  const productSlugRequests = new WeakMap();
  const setProductFormSyncState = (form, state) => {
    if (!form) return;
    const status = form.querySelector('[data-admin-product-save-status]');
    const label = status?.querySelector('[data-admin-product-save-status-label]');
    form.classList.toggle('is-dirty', state === 'dirty');
    form.classList.toggle('is-up-to-date', state === 'synced');
    if (label) label.textContent = state === 'dirty' ? 'Unsaved' : 'Up to date';
  };
  const markProductFormDirty = (target) => {
    const form = target?.closest?.('[data-admin-product-form]');
    if (!form || form.classList.contains('is-saving')) return;
    setProductFormSyncState(form, 'dirty');
  };
  const updateProductSubmitState = (form) => {
    if (!form) return;
    const submit = form.querySelector('[data-admin-product-submit]');
    if (!submit || form.classList.contains('is-saving')) return;
    submit.disabled = form.querySelector('[data-product-availability="pending"],[data-product-availability="unavailable"]') !== null;
  };
  const setProductNameState = (input, state, message) => {
    const field = input.closest('[data-product-name-field]');
    const status = field?.querySelector('[data-product-name-status]');
    const form = input.closest('[data-admin-product-form]');
    if (field) field.dataset.productAvailability = state;
    if (status) status.textContent = message;
    if (state === 'unavailable') input.setCustomValidity(message);
    else if (state === 'pending') input.setCustomValidity('Așteaptă verificarea disponibilității titlului.');
    else input.setCustomValidity('');
    updateProductSubmitState(form);
  };

  const checkProductName = (input) => {
    const previous = productNameRequests.get(input);
    if (previous?.timer) window.clearTimeout(previous.timer);
    previous?.controller?.abort();

    const name = input.value.trim();
    if (!name) {
      productNameRequests.delete(input);
      setProductNameState(input, 'idle', 'Denumirea pe care o vede clientul.');
      return;
    }

    setProductNameState(input, 'pending', 'Verificăm disponibilitatea titlului…');
    const timer = window.setTimeout(async () => {
      const controller = new AbortController();
      productNameRequests.set(input, { controller });
      const endpoint = new URL(input.dataset.availabilityUrl || '', window.location.href);
      endpoint.searchParams.set('nume', name);
      if (input.dataset.productId) endpoint.searchParams.set('exclude_id', input.dataset.productId);

      try {
        const response = await fetch(endpoint, {
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          signal: controller.signal,
        });
        const contentType = response.headers.get('Content-Type') || '';
        if (!response.ok || !contentType.includes('application/json')) throw new Error('Verificarea nu este disponibilă momentan.');
        const payload = await response.json();
        if (input.value.trim() !== name) return;
        setProductNameState(
          input,
          payload.available ? 'available' : 'unavailable',
          payload.message || (payload.available ? 'Acest titlu de produs este disponibil!' : 'Acest titlu există deja.')
        );
      } catch (error) {
        if (error.name === 'AbortError' || input.value.trim() !== name) return;
        setProductNameState(input, 'error', 'Nu am putut verifica acum; titlul va fi validat obligatoriu la salvare.');
      } finally {
        const active = productNameRequests.get(input);
        if (active?.controller === controller) productNameRequests.delete(input);
      }
    }, 360);

    productNameRequests.set(input, { timer });
  };

  const setProductSkuState = (input, state, message) => {
    const field = input.closest('[data-product-sku-field]');
    const status = field?.querySelector('[data-product-sku-status]');
    const form = input.closest('[data-admin-product-form]');
    if (field) field.dataset.productAvailability = state;
    if (status) status.textContent = message;
    if (state === 'unavailable') input.setCustomValidity(message);
    else if (state === 'pending') input.setCustomValidity('Așteaptă verificarea disponibilității SKU-ului.');
    else input.setCustomValidity('');
    updateProductSubmitState(form);
  };

  const checkProductSku = (input) => {
    const previous = productSkuRequests.get(input);
    if (previous?.timer) window.clearTimeout(previous.timer);
    previous?.controller?.abort();

    const sku = input.value.trim();
    if (!sku) {
      productSkuRequests.delete(input);
      setProductSkuState(input, 'idle', 'SKU-ul va fi generat automat la salvare.');
      return;
    }

    setProductSkuState(input, 'pending', 'Verificăm disponibilitatea SKU-ului…');
    const timer = window.setTimeout(async () => {
      const controller = new AbortController();
      productSkuRequests.set(input, { controller });
      const endpoint = new URL(input.dataset.availabilityUrl || '', window.location.href);
      endpoint.searchParams.set('sku', sku);
      if (input.dataset.variantId) endpoint.searchParams.set('exclude_variant_id', input.dataset.variantId);
      if (input.dataset.skuScope === 'primary') {
        endpoint.searchParams.set('scope', 'primary');
        if (input.dataset.productId) endpoint.searchParams.set('exclude_product_id', input.dataset.productId);
      }

      try {
        const response = await fetch(endpoint, {
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          signal: controller.signal,
        });
        const contentType = response.headers.get('Content-Type') || '';
        if (!response.ok || !contentType.includes('application/json')) throw new Error('Verificarea nu este disponibilă momentan.');
        const payload = await response.json();
        if (input.value.trim() !== sku) return;
        setProductSkuState(
          input,
          payload.available ? 'available' : 'unavailable',
          payload.message || (payload.available ? 'Acest SKU este disponibil!' : 'Acest SKU este deja folosit.')
        );
      } catch (error) {
        if (error.name === 'AbortError' || input.value.trim() !== sku) return;
        setProductSkuState(input, 'error', 'Nu am putut verifica acum; SKU-ul va fi validat obligatoriu la salvare.');
      } finally {
        const active = productSkuRequests.get(input);
        if (active?.controller === controller) productSkuRequests.delete(input);
      }
    }, 360);

    productSkuRequests.set(input, { timer });
  };

  const slugifyProductName = (value) => value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');

  const setProductSlugState = (input, state, message) => {
    const field = input.closest('[data-product-slug-field]');
    const status = field?.querySelector('[data-product-slug-status]');
    const form = input.closest('[data-admin-product-form]');
    if (field) field.dataset.productAvailability = state;
    if (status) status.textContent = message;
    if (state === 'unavailable') input.setCustomValidity(message);
    else if (state === 'pending') input.setCustomValidity('Așteaptă verificarea disponibilității numelui linkului.');
    else input.setCustomValidity('');
    updateProductSubmitState(form);
  };

  const checkProductSlug = (input) => {
    const previous = productSlugRequests.get(input);
    if (previous?.timer) window.clearTimeout(previous.timer);
    previous?.controller?.abort();

    const slug = input.value.trim();
    if (!slug) {
      productSlugRequests.delete(input);
      setProductSlugState(input, 'idle', 'Numele linkului se generează automat din titlu.');
      return;
    }

    setProductSlugState(input, 'pending', 'Verificăm disponibilitatea numelui linkului…');
    const timer = window.setTimeout(async () => {
      const controller = new AbortController();
      productSlugRequests.set(input, { controller });
      const endpoint = new URL(input.dataset.availabilityUrl || '', window.location.href);
      endpoint.searchParams.set('slug', slug);
      if (input.dataset.productId) endpoint.searchParams.set('exclude_id', input.dataset.productId);

      try {
        const response = await fetch(endpoint, {
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          signal: controller.signal,
        });
        const contentType = response.headers.get('Content-Type') || '';
        if (!response.ok || !contentType.includes('application/json')) throw new Error('Verificarea nu este disponibilă momentan.');
        const payload = await response.json();
        if (input.value.trim() !== slug) return;
        setProductSlugState(
          input,
          payload.available ? 'available' : 'unavailable',
          payload.message || (payload.available ? 'Acest nume de link este disponibil!' : 'Acest nume de link există deja.')
        );
      } catch (error) {
        if (error.name === 'AbortError' || input.value.trim() !== slug) return;
        setProductSlugState(input, 'error', 'Nu am putut verifica acum; numele linkului va fi validat obligatoriu la salvare.');
      } finally {
        const active = productSlugRequests.get(input);
        if (active?.controller === controller) productSlugRequests.delete(input);
      }
    }, 360);

    productSlugRequests.set(input, { timer });
  };

  const syncProductSlugFromName = (nameInput) => {
    const slugInput = nameInput.closest('[data-admin-product-form]')?.querySelector('[data-product-slug-input]');
    if (!slugInput || slugInput.dataset.slugAutofill !== 'true' || slugInput.dataset.slugManuallyEdited === 'true') return;
    const generatedSlug = slugifyProductName(nameInput.value);
    if (slugInput.value === generatedSlug) return;
    slugInput.value = generatedSlug;
    checkProductSlug(slugInput);
  };

  const normalizeCategorySearch = (value) => value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();

  const categoryChildNodes = (node) => Array.from(
    node.querySelector(':scope > [data-admin-category-children]')?.children || []
  ).filter((child) => child.matches('[data-admin-category-node]'));

  const filterCategoryNode = (node, term, ancestorMatches = false) => {
    const ownMatches = term === '' || normalizeCategorySearch(node.dataset.categorySearch || '').includes(term);
    const children = categoryChildNodes(node);
    let childMatches = false;
    children.forEach((child) => {
      if (filterCategoryNode(child, term, ancestorMatches || ownMatches)) childMatches = true;
    });
    const visible = ancestorMatches || ownMatches || childMatches;
    node.hidden = !visible;
    return visible;
  };

  const filterAdminCategoryPicker = (picker) => {
    const search = picker.querySelector('[data-admin-category-search]');
    const term = normalizeCategorySearch(search?.value || '');
    const clear = picker.querySelector('[data-admin-category-search-clear]');
    if (clear) clear.hidden = term === '';
    const tree = picker.querySelector('[data-admin-category-tree]');
    if (!tree) return;
    const roots = Array.from(tree.children).filter((child) => child.matches('[data-admin-category-node]'));
    const visibleCount = roots.reduce((count, node) => count + (filterCategoryNode(node, term) ? 1 : 0), 0);
    const empty = picker.querySelector('[data-admin-category-empty]');
    if (empty) empty.hidden = visibleCount > 0;
  };

  const updateAdminCategoryPicker = (picker) => {
    const inputs = Array.from(picker.querySelectorAll('[data-admin-category-checkbox]'));
    const selected = inputs.filter((input) => input.checked);
    const summary = picker.querySelector('[data-admin-category-summary]');
    const preview = picker.querySelector('[data-admin-category-preview]');
    const count = picker.querySelector('[data-admin-category-count]');
    const status = picker.querySelector('[data-admin-category-status]');
    const names = selected.map((input) => input.closest('[data-admin-category-node]')?.querySelector('.admin-category-option__copy strong')?.textContent.trim()).filter(Boolean);
    const selectionLabel = selected.length === 1 ? '1 categorie selectată' : `${selected.length} categorii selectate`;
    if (summary) summary.textContent = selected.length ? selectionLabel : 'Alege una sau mai multe categorii';
    if (preview) preview.textContent = names.length ? `${names.slice(0, 2).join(' · ')}${names.length > 2 ? ` +${names.length - 2}` : ''}` : 'Structură pe categorii și subcategorii';
    if (count) count.textContent = String(selected.length);
    if (status) status.textContent = selected.length ? selectionLabel : 'Selectează cel puțin o categorie.';
    if (inputs[0]) inputs[0].setCustomValidity(selected.length ? '' : 'Selectează cel puțin o categorie.');
    picker.classList.toggle('has-selection', selected.length > 0);
    picker.classList.toggle('is-invalid', picker.closest('form')?.classList.contains('was-validated') && selected.length === 0);
  };

  const closeAdminCategoryPicker = (picker) => {
    picker.removeAttribute('open');
    picker.querySelector('[data-admin-category-toggle]')?.setAttribute('aria-expanded', 'false');
    document.documentElement.classList.remove('has-admin-category-picker');
  };

  const initializeAdminCategoryPickers = (root) => {
    root.querySelectorAll('[data-admin-category-picker]').forEach((picker) => {
      if (picker.dataset.enhanced === 'true') return;
      picker.dataset.enhanced = 'true';
      const search = picker.querySelector('[data-admin-category-search]');
      search?.addEventListener('input', () => filterAdminCategoryPicker(picker));
      picker.querySelector('[data-admin-category-search-clear]')?.addEventListener('click', () => {
        if (!search) return;
        search.value = '';
        filterAdminCategoryPicker(picker);
        search.focus({ preventScroll: true });
      });
      picker.querySelectorAll('[data-admin-category-checkbox]').forEach((input) => {
        input.addEventListener('change', () => updateAdminCategoryPicker(picker));
      });
      picker.querySelectorAll('[data-admin-category-close],[data-admin-category-done]').forEach((button) => {
        button.addEventListener('click', () => closeAdminCategoryPicker(picker));
      });
      picker.addEventListener('toggle', () => {
        picker.querySelector('[data-admin-category-toggle]')?.setAttribute('aria-expanded', picker.open ? 'true' : 'false');
        document.documentElement.classList.toggle('has-admin-category-picker', picker.open);
        if (picker.open) requestAnimationFrame(() => search?.focus({ preventScroll: true }));
      });
      updateAdminCategoryPicker(picker);
      filterAdminCategoryPicker(picker);
    });
  };

  const productOptionRebuildTimers = new WeakMap();
  const productOptionsKey = (options) => JSON.stringify(options || {});
  const parseProductDecimal = (value) => {
    const normalized = String(value || '').trim().replace(/\s+/g, '').replace(',', '.');
    if (!/^\d+(?:\.\d{1,2})?$/.test(normalized)) return null;
    return Number.parseFloat(normalized);
  };
  const formatProductMoney = (value) => new Intl.NumberFormat('ro-RO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(Math.max(0, value || 0));

  const readProductOptionGroups = (form, validate = true) => {
    const groups = [];
    let complete = true;
    let combinationCount = 1;
    const seenGroups = new Set();
    form.querySelectorAll('[data-product-option-group]').forEach((group) => {
      const nameInput = group.querySelector('[data-product-option-group-name]');
      const name = nameInput?.value.trim() || '';
      const values = [];
      const seenValues = new Set();
      group.querySelectorAll('[data-product-option-value]').forEach((input) => {
        const value = input.value.trim();
        input.setCustomValidity('');
        if (!value) {
          complete = false;
          return;
        }
        const key = value.toLocaleLowerCase('ro-RO');
        if (seenValues.has(key)) {
          input.setCustomValidity('Această valoare apare deja în același grup.');
          complete = false;
          return;
        }
        seenValues.add(key);
        values.push(value);
      });
      nameInput?.setCustomValidity('');
      const nameKey = name.toLocaleLowerCase('ro-RO');
      if (!name || values.length === 0) complete = false;
      if (name && seenGroups.has(nameKey)) {
        nameInput?.setCustomValidity('Există deja o alegere cu acest nume.');
        complete = false;
      }
      if (name) seenGroups.add(nameKey);
      combinationCount *= Math.max(1, values.length);
      groups.push({ name, values });
    });
    if (groups.length > 4 || combinationCount > 100) complete = false;
    if (validate && combinationCount > 100) {
      const last = form.querySelector('[data-product-option-group]:last-child [data-product-option-value]');
      last?.setCustomValidity('Aceste alegeri creează peste 100 de combinații. Elimină o valoare.');
    }
    return { groups, complete, combinationCount: groups.length ? combinationCount : 1 };
  };

  const productOptionCombinations = (groups) => groups.reduce((combinations, group) => {
    const next = [];
    combinations.forEach((combination) => group.values.forEach((value) => {
      next.push({ ...combination, [group.name]: value });
    }));
    return next;
  }, [{}]);

  const reindexProductOptionGroups = (form) => {
    form.querySelectorAll('[data-product-option-group]').forEach((group, groupIndex) => {
      const number = group.querySelector('[data-product-option-group-number]');
      if (number) number.textContent = String(groupIndex + 1);
      const name = group.querySelector('[data-product-option-group-name]');
      if (name) name.name = `option_groups[${groupIndex}][name]`;
      const values = group.querySelectorAll('[data-product-option-value]');
      const count = group.querySelector('[data-product-option-value-count]');
      if (count) count.textContent = `${values.length} ${values.length === 1 ? 'opțiune' : 'opțiuni'}`;
      values.forEach((input, valueIndex) => {
        input.name = `option_groups[${groupIndex}][values][${valueIndex}]`;
        const item = input.closest('.admin-product-option-value');
        const valueNumber = item?.querySelector('[data-product-option-value-number]');
        if (valueNumber) valueNumber.textContent = String(valueIndex + 1);
        item?.style.setProperty('--option-value-index', String(valueIndex));
      });
    });
  };

  const resetClonedVariantIdentity = (card) => {
    const id = card.querySelector('[data-product-variant-id]');
    if (id) id.value = '';
    const sku = card.querySelector('[data-product-sku-input]');
    if (sku) {
      sku.value = '';
      sku.dataset.variantId = '';
      sku.setCustomValidity('');
    }
    const skuField = card.querySelector('[data-product-sku-field]');
    if (skuField) skuField.dataset.productAvailability = 'idle';
    const skuStatus = card.querySelector('[data-product-sku-status]');
    if (skuStatus) skuStatus.textContent = 'Lasă gol pentru generare automată.';
    const ean = card.querySelector('input[name$="[ean]"]');
    if (ean) ean.value = '';
    const trackInConta = card.querySelector('[data-product-variant-track-input]');
    if (trackInConta) trackInConta.checked = false;
  };

  const setProductVariantCommerceMode = (card, hasCombinations) => {
    card.classList.toggle('is-simple-product', !hasCombinations);
    const sku = card.querySelector('[data-product-variant-sku-field]');
    const track = card.querySelector('[data-product-variant-track]');
    if (sku) sku.hidden = !hasCombinations;
    if (track) track.hidden = !hasCombinations;
  };

  const updateProductCommerceMode = (form, hasCombinations) => {
    form.classList.toggle('has-product-combinations', hasCombinations);
    form.querySelectorAll('[data-product-variant]').forEach((card) => setProductVariantCommerceMode(card, hasCombinations));
  };

  const reindexProductVariant = (card, index, options) => {
    card.dataset.optionsJson = productOptionsKey(options);
    card.querySelectorAll('[name^="variants["]').forEach((field) => {
      field.name = field.name.replace(/^variants\[\d+\]/, `variants[${index}]`);
    });
    const optionsInput = card.querySelector('[data-product-variant-options]');
    if (optionsInput) optionsInput.value = productOptionsKey(options);
    const number = card.querySelector('[data-product-variant-number]');
    if (number) number.textContent = String(index + 1);
    const header = card.querySelector('[data-product-variant-header]');
    const hasOptions = Object.keys(options).length > 0;
    if (header) header.hidden = !hasOptions;
    const chips = card.querySelector('[data-product-variant-chips]');
    if (chips) {
      chips.replaceChildren();
      Object.entries(options).forEach(([name, value]) => {
        const chip = document.createElement('i');
        const label = document.createElement('small');
        label.textContent = name;
        chip.append(label, document.createTextNode(value));
        chips.append(chip);
      });
    }
    setProductVariantCommerceMode(card, hasOptions);
  };

  const updateProductDiscount = (discount) => {
    if (!discount) return;
    const toggle = discount.querySelector('[data-product-discount-toggle]');
    const fields = discount.querySelector('[data-product-discount-fields]');
    const type = discount.querySelector('[data-product-discount-type]');
    const value = discount.querySelector('[data-product-discount-value]');
    const unit = discount.querySelector('[data-product-discount-unit]');
    const card = discount.closest('[data-product-variant]');
    const fullPrice = parseProductDecimal(card?.querySelector('[data-product-variant-full-price]')?.value);
    const enabled = Boolean(toggle?.checked);
    if (fields) fields.hidden = !enabled;
    if (type) type.disabled = !enabled;
    if (value) {
      value.disabled = !enabled;
      value.required = enabled;
      value.setCustomValidity('');
    }
    if (unit) unit.textContent = type?.value === 'fixed' ? 'LEI' : '%';
    discount.classList.toggle('is-active', enabled);

    let finalPrice = fullPrice ?? 0;
    if (enabled && value) {
      const reduction = parseProductDecimal(value.value);
      if (reduction === null || reduction <= 0) {
        value.setCustomValidity('Introdu o reducere mai mare decât zero.');
      } else if (type?.value === 'fixed') {
        if (fullPrice === null || reduction >= fullPrice) value.setCustomValidity('Reducerea trebuie să fie mai mică decât prețul întreg.');
        else finalPrice = fullPrice - reduction;
      } else if (reduction >= 100) {
        value.setCustomValidity('Procentul trebuie să fie mai mic decât 100%.');
      } else {
        finalPrice = (fullPrice ?? 0) * (1 - reduction / 100);
      }
    }
    const result = discount.querySelector('[data-product-discount-result]');
    if (result) result.textContent = `${formatProductMoney(finalPrice)} RON`;
  };

  const setProductGlobalDiscountFeedback = (section, state, message) => {
    const feedback = section?.querySelector('[data-product-global-discount-feedback]');
    if (!feedback) return;
    feedback.dataset.state = state;
    feedback.textContent = message;
  };

  const updateProductGlobalDiscount = (section, resetFeedback = false) => {
    if (!section) return;
    const toggle = section.querySelector('[data-product-global-discount-toggle]');
    const fields = section.querySelector('[data-product-global-discount-fields]');
    const type = section.querySelector('[data-product-global-discount-type]');
    const value = section.querySelector('[data-product-global-discount-value]');
    const unit = section.querySelector('[data-product-global-discount-unit]');
    const enabled = Boolean(toggle?.checked);
    section.classList.toggle('is-active', enabled);
    if (toggle) toggle.setAttribute('aria-expanded', enabled ? 'true' : 'false');
    if (fields) fields.hidden = !enabled;
    if (type) type.disabled = !enabled;
    if (value) {
      value.disabled = !enabled;
      value.setCustomValidity('');
    }
    if (unit) unit.textContent = type?.value === 'fixed' ? 'LEI' : '%';
    if (resetFeedback) {
      setProductGlobalDiscountFeedback(
        section,
        'idle',
        enabled
          ? 'Reducerea va fi copiată în toate combinațiile după ce apeși „Aplică reducerea”.'
          : 'Reducerile deja aplicate rămân salvate și pot fi modificate separat.'
      );
    }
  };

  const applyProductGlobalDiscount = (section) => {
    const form = section?.closest('[data-admin-product-form]');
    const type = section?.querySelector('[data-product-global-discount-type]');
    const value = section?.querySelector('[data-product-global-discount-value]');
    if (!section || !form || !type || !value) return;

    value.setCustomValidity('');
    const reduction = parseProductDecimal(value.value);
    if (reduction === null || reduction <= 0) {
      value.setCustomValidity('Introdu o reducere mai mare decât zero.');
      value.reportValidity();
      setProductGlobalDiscountFeedback(section, 'error', 'Completează o valoare validă pentru reducere.');
      return;
    }
    if (type.value === 'percent' && reduction >= 100) {
      value.setCustomValidity('Procentul trebuie să fie mai mic decât 100%.');
      value.reportValidity();
      setProductGlobalDiscountFeedback(section, 'error', 'Procentul trebuie să fie între 0 și 100%.');
      return;
    }

    const cards = Array.from(form.querySelectorAll('[data-product-variant]'));
    const prices = cards.map((card) => parseProductDecimal(card.querySelector('[data-product-variant-full-price]')?.value));
    if (!cards.length || prices.some((price) => price === null || price <= 0)) {
      value.setCustomValidity('Completează mai întâi prețul întreg pentru fiecare combinație.');
      value.reportValidity();
      setProductGlobalDiscountFeedback(section, 'error', 'Completează toate prețurile întregi înainte de aplicarea reducerii.');
      return;
    }
    if (type.value === 'fixed' && prices.some((price) => reduction >= price)) {
      value.setCustomValidity('Suma redusă trebuie să fie mai mică decât fiecare preț întreg.');
      value.reportValidity();
      setProductGlobalDiscountFeedback(section, 'error', 'Suma aleasă este prea mare pentru cel puțin una dintre combinații.');
      return;
    }

    const normalizedValue = reduction.toFixed(2).replace('.', ',');
    cards.forEach((card) => {
      const discount = card.querySelector('[data-product-discount]');
      const discountToggle = discount?.querySelector('[data-product-discount-toggle]');
      const discountType = discount?.querySelector('[data-product-discount-type]');
      const discountValue = discount?.querySelector('[data-product-discount-value]');
      if (!discount || !discountToggle || !discountType || !discountValue) return;
      discountToggle.checked = true;
      discountType.value = type.value;
      discountValue.value = normalizedValue;
      updateProductDiscount(discount);
    });

    markProductFormDirty(section);
    section.classList.remove('is-applied');
    void section.offsetWidth;
    section.classList.add('is-applied');
    const count = cards.length;
    const valueLabel = type.value === 'fixed' ? `${formatProductMoney(reduction)} lei` : `${formatProductMoney(reduction).replace(',00', '')}%`;
    setProductGlobalDiscountFeedback(
      section,
      'success',
      count === 1
        ? `Reducerea de ${valueLabel} a fost aplicată produsului. O poți ajusta mai jos.`
        : `Reducerea de ${valueLabel} a fost aplicată celor ${count} combinații. Poți ajusta separat oricare dintre ele.`
    );
    window.setTimeout(() => section.classList.remove('is-applied'), 700);
  };

  const updateProductUnlimitedStock = (card) => {
    const toggle = card.querySelector('[data-product-stock-unlimited]');
    const stock = card.querySelector('[data-product-variant-stock]');
    if (!toggle || !stock) return;
    stock.readOnly = toggle.checked;
    stock.setAttribute('aria-readonly', toggle.checked ? 'true' : 'false');
    card.classList.toggle('has-unlimited-stock', toggle.checked);
  };

  const updateProductOptionsSummary = (form, state) => {
    const title = form.querySelector('[data-product-options-summary-title]');
    const detail = form.querySelector('[data-product-options-summary-detail]');
    const empty = form.querySelector('[data-product-options-empty]');
    const notice = form.querySelector('[data-product-variants-notice]');
    const count = form.querySelector('[data-product-variant-count]');
    const groupCount = state.groups.length;
    updateProductCommerceMode(form, groupCount > 0 && state.complete);
    if (empty) empty.hidden = groupCount > 0;
    if (notice) notice.hidden = groupCount === 0;
    if (groupCount === 0) {
      if (title) title.textContent = 'Produs simplu, fără opțiuni';
      if (detail) detail.textContent = 'Clientul vede direct butonul de adăugare în coș.';
      if (count) count.textContent = 'Un singur preț';
      return;
    }
    if (!state.complete) {
      if (title) title.textContent = 'Mai lipsește un mic pas';
      if (detail) detail.textContent = 'Completează numele alegerii și toate valorile ei.';
      if (count) count.textContent = 'Combinațiile apar după completare';
      return;
    }
    if (title) title.textContent = `${groupCount} ${groupCount === 1 ? 'alegere configurată' : 'alegeri configurate'}`;
    const equation = state.groups.map((group) => `${group.values.length} ${group.name.toLocaleLowerCase('ro-RO')}`).join(' × ');
    if (detail) detail.textContent = `${equation} = ${state.combinationCount} ${state.combinationCount === 1 ? 'combinație' : 'combinații'}, create automat.`;
    if (count) count.textContent = `${state.combinationCount} ${state.combinationCount === 1 ? 'combinație' : 'combinații'}`;
  };

  const rebuildProductVariants = (form) => {
    const container = form.querySelector('[data-product-variants]');
    if (!container) return;
    const state = readProductOptionGroups(form);
    updateProductOptionsSummary(form, state);
    if (!state.complete) return;
    const combinations = productOptionCombinations(state.groups);
    const currentCards = Array.from(container.querySelectorAll('[data-product-variant]'));
    const byOptions = new Map(currentCards.map((card) => [card.dataset.optionsJson || '{}', card]));
    const base = currentCards[0];
    if (!base) return;
    const fragment = document.createDocumentFragment();
    combinations.forEach((options, index) => {
      const key = productOptionsKey(options);
      let card = byOptions.get(key);
      if (!card) {
        card = base.cloneNode(true);
        resetClonedVariantIdentity(card);
      }
      reindexProductVariant(card, index, options);
      updateProductUnlimitedStock(card);
      updateProductDiscount(card.querySelector('[data-product-discount]'));
      fragment.append(card);
    });
    container.replaceChildren(fragment);
  };

  const scheduleProductVariantRebuild = (form) => {
    const active = productOptionRebuildTimers.get(form);
    if (active) window.clearTimeout(active);
    productOptionRebuildTimers.set(form, window.setTimeout(() => {
      productOptionRebuildTimers.delete(form);
      rebuildProductVariants(form);
    }, 180));
  };

  const createProductOptionValue = (value = '') => {
    const label = document.createElement('label');
    label.className = 'admin-product-option-value is-new';
    label.innerHTML = '<span class="admin-product-option-value__number" aria-hidden="true" data-product-option-value-number>1</span><input maxlength="100" placeholder="Ex: 3–6 luni" required data-product-option-value><button type="button" aria-label="Șterge această valoare" data-product-option-value-remove><svg viewBox="0 0 18 18"><path d="m5 5 8 8M13 5l-8 8"></path></svg></button>';
    label.querySelector('input').value = value;
    return label;
  };

  const createProductOptionGroup = (name = '') => {
    const group = document.createElement('article');
    group.className = 'admin-product-option-group is-new';
    group.dataset.productOptionGroup = '';
    group.innerHTML = '<div class="admin-product-option-group__number" aria-hidden="true"><small>Alegerea</small><strong data-product-option-group-number></strong></div><div class="admin-product-option-group__content"><div class="admin-product-option-group__topline"><label class="admin-product-field"><span>Ce alege clientul? <b>*</b></span><input maxlength="80" placeholder="Ex: Mărime" required data-product-option-group-name><small>Un nume scurt și clar, precum „Mărime” sau „Culoare”.</small></label><button type="button" class="admin-product-option-group__remove" aria-label="Șterge această alegere" data-product-option-group-remove><svg viewBox="0 0 20 20"><path d="m6 6 8 8M14 6l-8 8"></path></svg></button></div><div class="admin-product-option-group__values-wrap"><div class="admin-product-option-group__values-head"><span class="admin-product-option-group__values-label">Ce variante poate alege? <b>*</b></span><span class="admin-product-option-group__values-count" data-product-option-value-count>1 opțiune</span></div><div class="admin-product-option-group__values" data-product-option-values></div><button type="button" class="admin-product-option-group__add-value" data-product-option-value-add><svg viewBox="0 0 18 18"><path d="M9 3v12M3 9h12"></path></svg><span>Adaugă o valoare</span></button></div></div>';
    group.querySelector('[data-product-option-group-name]').value = name;
    group.querySelector('[data-product-option-values]').append(createProductOptionValue());
    return group;
  };

  const animateProductOptionRemoval = (element, onComplete) => {
    if (!element) return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      onComplete();
      return;
    }
    element.classList.add('is-removing');
    let completed = false;
    const finish = () => {
      if (completed) return;
      completed = true;
      onComplete();
    };
    element.addEventListener('animationend', finish, { once: true });
    window.setTimeout(finish, 320);
  };

  const initializeProductOptions = (root) => {
    root.querySelectorAll('[data-admin-product-form]').forEach((form) => {
      if (form.dataset.productOptionsEnhanced === 'true') return;
      form.dataset.productOptionsEnhanced = 'true';
      reindexProductOptionGroups(form);
      form.querySelectorAll('[data-product-variant]').forEach((card) => {
        updateProductUnlimitedStock(card);
        updateProductDiscount(card.querySelector('[data-product-discount]'));
      });
      form.querySelectorAll('[data-product-global-discount]').forEach((section) => updateProductGlobalDiscount(section));
      updateProductOptionsSummary(form, readProductOptionGroups(form, false));
    });
  };

  const initializeProductAvailability = (root) => {
    root.querySelectorAll('[data-admin-product-form]').forEach((form) => setProductFormSyncState(form, 'synced'));
    root.querySelectorAll('[data-product-short-description]').forEach((input) => updateProductShortDescriptionCount(input));
    root.querySelectorAll('[data-product-name-input]').forEach((input) => {
      if (input.value.trim()) checkProductName(input);
      syncProductSlugFromName(input);
    });
    root.querySelectorAll('[data-product-sku-input]').forEach((input) => {
      if (input.value.trim()) checkProductSku(input);
    });
    root.querySelectorAll('[data-product-slug-input]').forEach((input) => {
      if (input.value.trim()) checkProductSlug(input);
    });
    initializeAdminCategoryPickers(root);
    initializeProductOptions(root);
    initializeProductSeo(root);
  };

  const updateProductShortDescriptionCount = (input) => {
    const count = Array.from(input.value || '').length;
    const output = input.closest('.admin-product-field')?.querySelector('[data-product-short-description-count]');
    if (!output) return;
    output.textContent = String(count);
    output.closest('.admin-product-field__character-count')?.classList.toggle('is-near-limit', count >= 180);
  };

  const updateProductSeoCounter = (input, output, recommendedLength) => {
    if (!input || !output) return;
    const count = Array.from(input.value || '').length;
    const value = output.querySelector('strong');
    if (value) value.textContent = String(count);
    output.classList.toggle('is-near-limit', count >= Math.round(recommendedLength * .82) && count <= recommendedLength);
    output.classList.toggle('is-over-limit', count > recommendedLength);
  };

  const productSeoPreviewUrl = (section, slug) => {
    const preview = section.querySelector('[data-product-seo-base-url]');
    const baseUrl = preview?.dataset.productSeoBaseUrl || '/produs/';
    try {
      const url = new URL(`${baseUrl.replace(/\/$/, '')}/${slug}/`, window.location.origin);
      const path = url.pathname.split('/').filter(Boolean).join(' › ');
      return `${url.host.replace(/^www\./, '')}${path ? ` › ${path}` : ''}`;
    } catch (error) {
      return slug;
    }
  };

  const updateProductSeoPreview = (section) => {
    if (!section) return;
    const form = section.closest('[data-admin-product-form]');
    if (!form) return;

    const seoTitle = section.querySelector('[data-product-seo-title]');
    const seoDescription = section.querySelector('[data-product-seo-description]');
    const productName = form.querySelector('[data-product-name-input]');
    const shortDescription = form.querySelector('[data-product-short-description]');
    const slugInput = form.querySelector('[data-product-slug-input]');
    const indexable = section.querySelector('[data-product-seo-indexable]');
    const title = seoTitle?.value.trim() || productName?.value.trim() || 'Titlul produsului';
    const description = seoDescription?.value.trim() || shortDescription?.value.trim() || 'Descrierea produsului va apărea aici pentru a ajuta familia să aleagă.';
    const slug = slugInput?.value.trim().replace(/^\/+|\/+$/g, '') || 'numele-produsului';

    const previewTitle = section.querySelector('[data-product-seo-preview-title]');
    const previewDescription = section.querySelector('[data-product-seo-preview-description]');
    const previewHost = section.querySelector('[data-product-seo-preview-host]');
    const previewUrl = section.querySelector('[data-product-seo-preview-url]');
    const visibilityStatus = section.querySelector('[data-product-seo-visibility-status] span');
    if (previewTitle) previewTitle.textContent = title;
    if (previewDescription) previewDescription.textContent = description;
    if (previewHost) previewHost.textContent = window.location.host.replace(/^www\./, '') || 'La Măriuca Boutique';
    if (previewUrl) previewUrl.textContent = productSeoPreviewUrl(section, slug);
    if (visibilityStatus) visibilityStatus.textContent = indexable?.checked ? 'Vizibilitate activă' : 'Vizibilitate oprită';

    updateProductSeoCounter(seoTitle, section.querySelector('[data-product-seo-title-count]'), 60);
    updateProductSeoCounter(seoDescription, section.querySelector('[data-product-seo-description-count]'), 160);
  };

  const initializeProductSeo = (root) => {
    root.querySelectorAll('[data-product-seo]').forEach((section) => updateProductSeoPreview(section));
  };

  document.addEventListener('input', (event) => {
    const globalDiscount = event.target.closest?.('[data-product-global-discount]');
    if (!globalDiscount) markProductFormDirty(event.target);
    if (globalDiscount && event.target.matches?.('[data-product-global-discount-value]')) {
      event.target.setCustomValidity('');
      setProductGlobalDiscountFeedback(globalDiscount, 'idle', 'Apasă „Aplică reducerea” pentru a copia această valoare în toate combinațiile.');
    }
    const productForm = event.target.closest?.('[data-admin-product-form]');
    const shortDescription = event.target.closest?.('[data-product-short-description]');
    if (shortDescription) updateProductShortDescriptionCount(shortDescription);
    if (productForm && event.target.matches?.('[data-product-option-group-name],[data-product-option-value]')) {
      reindexProductOptionGroups(productForm);
      updateProductOptionsSummary(productForm, readProductOptionGroups(productForm));
      scheduleProductVariantRebuild(productForm);
    }
    const discount = event.target.closest?.('[data-product-discount]');
    if (discount && event.target.matches?.('[data-product-discount-value],[data-product-variant-full-price]')) {
      updateProductDiscount(discount);
    } else if (event.target.matches?.('[data-product-variant-full-price]')) {
      updateProductDiscount(event.target.closest('[data-product-variant]')?.querySelector('[data-product-discount]'));
    }
    const nameInput = event.target.closest?.('[data-product-name-input]');
    if (nameInput) {
      checkProductName(nameInput);
      syncProductSlugFromName(nameInput);
    }
    const skuInput = event.target.closest?.('[data-product-sku-input]');
    if (skuInput) checkProductSku(skuInput);
    const slugInput = event.target.closest?.('[data-product-slug-input]');
    if (slugInput) {
      slugInput.dataset.slugManuallyEdited = 'true';
      checkProductSlug(slugInput);
    }
    if (productForm && event.target.matches?.('[data-product-seo-title],[data-product-seo-description],[data-product-name-input],[data-product-short-description],[data-product-slug-input]')) {
      productForm.querySelectorAll('[data-product-seo]').forEach((section) => updateProductSeoPreview(section));
    }
  });

  document.addEventListener('change', (event) => {
    const globalDiscount = event.target.closest?.('[data-product-global-discount]');
    if (!globalDiscount) markProductFormDirty(event.target);
    if (globalDiscount && event.target.matches?.('[data-product-global-discount-toggle],[data-product-global-discount-type]')) {
      updateProductGlobalDiscount(globalDiscount, true);
      if (event.target.matches('[data-product-global-discount-toggle]') && event.target.checked) {
        requestAnimationFrame(() => globalDiscount.querySelector('[data-product-global-discount-value]')?.focus({ preventScroll: true }));
      }
    }
    const card = event.target.closest?.('[data-product-variant]');
    if (event.target.matches?.('[data-product-stock-unlimited]') && card) updateProductUnlimitedStock(card);
    const discount = event.target.closest?.('[data-product-discount]');
    if (discount && event.target.matches?.('[data-product-discount-toggle],[data-product-discount-type]')) updateProductDiscount(discount);
    if (event.target.matches?.('[data-product-seo-indexable]')) updateProductSeoPreview(event.target.closest('[data-product-seo]'));
  });

  document.addEventListener('click', (event) => {
    const form = event.target.closest?.('[data-admin-product-form]');
    if (!form) return;
    const applyGlobalDiscount = event.target.closest('[data-product-global-discount-apply]');
    if (applyGlobalDiscount) {
      event.preventDefault();
      applyProductGlobalDiscount(applyGlobalDiscount.closest('[data-product-global-discount]'));
      return;
    }
    const groupsContainer = form.querySelector('[data-product-option-groups]');
    const addGroup = event.target.closest('[data-product-option-group-add]');
    const preset = event.target.closest('[data-product-option-preset]');
    if (addGroup || preset) {
      event.preventDefault();
      if (!groupsContainer) return;
      const groups = groupsContainer.querySelectorAll('[data-product-option-group]');
      if (groups.length >= 4) {
        const lastName = groups[groups.length - 1]?.querySelector('[data-product-option-group-name]');
        lastName?.setCustomValidity('Poți adăuga cel mult 4 tipuri de alegeri.');
        lastName?.reportValidity();
        return;
      }
      const group = createProductOptionGroup(preset?.dataset.productOptionPreset || '');
      groupsContainer.append(group);
      reindexProductOptionGroups(form);
      updateProductOptionsSummary(form, readProductOptionGroups(form));
      markProductFormDirty(group);
      requestAnimationFrame(() => {
        const focusTarget = preset ? group.querySelector('[data-product-option-value]') : group.querySelector('[data-product-option-group-name]');
        focusTarget?.focus({ preventScroll: false });
      });
      return;
    }

    const addValue = event.target.closest('[data-product-option-value-add]');
    if (addValue) {
      event.preventDefault();
      const group = addValue.closest('[data-product-option-group]');
      const values = group?.querySelector('[data-product-option-values]');
      if (!group || !values) return;
      if (values.querySelectorAll('[data-product-option-value]').length >= 20) return;
      const value = createProductOptionValue();
      values.append(value);
      reindexProductOptionGroups(form);
      updateProductOptionsSummary(form, readProductOptionGroups(form));
      markProductFormDirty(value);
      value.querySelector('input')?.focus({ preventScroll: false });
      return;
    }

    const removeValue = event.target.closest('[data-product-option-value-remove]');
    if (removeValue) {
      event.preventDefault();
      const group = removeValue.closest('[data-product-option-group]');
      const value = removeValue.closest('.admin-product-option-value');
      const allValues = group?.querySelectorAll('.admin-product-option-value') || [];
      if (allValues.length <= 1) {
        const input = value?.querySelector('[data-product-option-value]');
        if (input) input.value = '';
        value?.classList.remove('is-attention');
        void value?.offsetWidth;
        value?.classList.add('is-attention');
        input?.focus({ preventScroll: true });
      } else {
        removeValue.disabled = true;
        markProductFormDirty(group);
        animateProductOptionRemoval(value, () => {
          value?.remove();
          reindexProductOptionGroups(form);
          rebuildProductVariants(form);
        });
        return;
      }
      reindexProductOptionGroups(form);
      rebuildProductVariants(form);
      markProductFormDirty(group);
      return;
    }

    const removeGroup = event.target.closest('[data-product-option-group-remove]');
    if (removeGroup) {
      event.preventDefault();
      const group = removeGroup.closest('[data-product-option-group]');
      markProductFormDirty(form);
      removeGroup.disabled = true;
      animateProductOptionRemoval(group, () => {
        group?.remove();
        reindexProductOptionGroups(form);
        rebuildProductVariants(form);
      });
    }
  });

  initializeProductAvailability(document);

  document.addEventListener('click', (event) => {
    document.querySelectorAll('[data-admin-category-picker][open]').forEach((picker) => {
      if (!picker.contains(event.target)) closeAdminCategoryPicker(picker);
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const picker = document.querySelector('[data-admin-category-picker][open]');
    if (!picker) return;
    event.preventDefault();
    closeAdminCategoryPicker(picker);
    picker.querySelector('[data-admin-category-toggle]')?.focus({ preventScroll: true });
  });

  const productEditorDialog = document.querySelector('[data-admin-product-editor-dialog]');
  const productEditorContent = productEditorDialog?.querySelector('[data-admin-product-editor-content]');
  const productDiscardLayer = productEditorDialog?.querySelector('[data-admin-product-discard-layer]');
  const productEditorLoading = productEditorContent?.innerHTML || '';
  let productEditorTrigger = null;
  let productEditorRequest = null;
  let productDiscardReturnFocus = null;

  const productEditorHasUnsavedChanges = () => {
    const form = productEditorContent?.querySelector('[data-admin-product-form]');
    return Boolean(form?.classList.contains('is-dirty') && !form.classList.contains('is-saving') && !form.classList.contains('is-saved'));
  };

  const hideProductDiscardConfirmation = (restoreFocus = true) => {
    if (!productDiscardLayer || productDiscardLayer.hidden) return;
    const focusTarget = productDiscardReturnFocus;
    productDiscardReturnFocus = null;
    productDiscardLayer.classList.remove('is-visible');
    window.setTimeout(() => {
      productDiscardLayer.hidden = true;
      if (restoreFocus && focusTarget instanceof HTMLElement && productEditorDialog?.open) {
        focusTarget.focus({ preventScroll: true });
      }
    }, reducedMotion ? 0 : 180);
  };

  const showProductDiscardConfirmation = (trigger = null) => {
    if (!productDiscardLayer || !productEditorDialog?.open) return;
    productDiscardReturnFocus = trigger instanceof HTMLElement ? trigger : productEditorContent?.querySelector('[data-admin-product-form]');
    productDiscardLayer.hidden = false;
    requestAnimationFrame(() => {
      productDiscardLayer.classList.add('is-visible');
      productDiscardLayer.querySelector('[data-admin-product-discard-stay]')?.focus({ preventScroll: true });
    });
  };

  const closeProductEditor = () => {
    if (!productEditorDialog?.open) return;
    hideProductDiscardConfirmation(false);
    productEditorRequest?.abort();
    productEditorRequest = null;
    productEditorDialog.classList.remove('is-visible', 'is-success');
    document.documentElement.classList.remove('has-admin-product-editor');
    window.setTimeout(() => {
      if (productEditorDialog.open) productEditorDialog.close();
    }, reducedMotion ? 0 : 230);
  };

  const requestProductEditorClose = (trigger = null) => {
    if (productEditorHasUnsavedChanges()) {
      showProductDiscardConfirmation(trigger);
      return;
    }
    closeProductEditor();
  };

  const showProductEditorError = (message) => {
    if (!productEditorContent) return;
    productEditorContent.innerHTML = `<div class="admin-product-editor__error-state"><span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 8v5M12 17h.01"></path><circle cx="12" cy="12" r="9"></circle></svg></span><h2>Nu am putut deschide editorul</h2><p>${message}</p><button type="button" data-admin-product-editor-retry>Încearcă din nou</button></div>`;
  };

  const loadProductEditor = async (trigger) => {
    if (!productEditorDialog || !productEditorContent) return;
    productEditorRequest?.abort();
    productEditorRequest = new AbortController();
    productEditorContent.innerHTML = productEditorLoading;
    const modalUrl = trigger.dataset.modalUrl;
    if (!modalUrl) return;
    try {
      const response = await fetch(modalUrl, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        signal: productEditorRequest.signal,
      });
      const markup = await response.text();
      if (!response.ok || response.redirected || !markup.includes('data-admin-product-form')) {
        throw new Error('Produsul nu mai este disponibil sau sesiunea a expirat.');
      }
      productEditorContent.innerHTML = markup;
      initializeProductAvailability(productEditorContent);
      requestAnimationFrame(() => productEditorContent.querySelector('input:not([type="hidden"])')?.focus({ preventScroll: true }));
    } catch (error) {
      if (error.name === 'AbortError') return;
      showProductEditorError(error.message || 'A apărut o problemă temporară. Încearcă din nou.');
    } finally {
      productEditorRequest = null;
    }
  };

  document.querySelectorAll('[data-admin-product-editor-trigger]').forEach((trigger) => {
    trigger.addEventListener('click', (event) => {
      if (!productEditorDialog || typeof productEditorDialog.showModal !== 'function' || !window.fetch) return;
      event.preventDefault();
      productEditorTrigger = trigger;
      productEditorDialog.showModal();
      document.documentElement.classList.add('has-admin-product-editor');
      requestAnimationFrame(() => productEditorDialog.classList.add('is-visible'));
      loadProductEditor(trigger);
    });
  });

  productEditorDialog?.addEventListener('click', (event) => {
    const stayButton = event.target.closest('[data-admin-product-discard-stay]');
    if (stayButton || event.target === productDiscardLayer) {
      hideProductDiscardConfirmation();
      return;
    }
    if (event.target.closest('[data-admin-product-discard-leave]')) {
      hideProductDiscardConfirmation(false);
      closeProductEditor();
      return;
    }
    const closeTrigger = event.target.closest('[data-admin-product-editor-close]');
    if (event.target === productEditorDialog || closeTrigger) {
      requestProductEditorClose(closeTrigger);
      return;
    }
    if (event.target.closest('[data-admin-product-editor-reload]')) window.location.reload();
    if (event.target.closest('[data-admin-product-editor-retry]') && productEditorTrigger) loadProductEditor(productEditorTrigger);
  });
  productEditorDialog?.addEventListener('cancel', (event) => {
    event.preventDefault();
    if (productDiscardLayer && !productDiscardLayer.hidden) {
      hideProductDiscardConfirmation();
      return;
    }
    requestProductEditorClose(productEditorDialog.querySelector('[data-admin-product-editor-close]'));
  });
  productEditorDialog?.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape' || !productDiscardLayer || productDiscardLayer.hidden) return;
    event.preventDefault();
    event.stopPropagation();
    hideProductDiscardConfirmation();
  }, true);
  productEditorDialog?.addEventListener('close', () => {
    if (productDiscardLayer) {
      productDiscardLayer.hidden = true;
      productDiscardLayer.classList.remove('is-visible');
    }
    productDiscardReturnFocus = null;
    productEditorDialog.classList.remove('is-visible', 'is-success');
    document.documentElement.classList.remove('has-admin-product-editor');
    if (productEditorContent) productEditorContent.innerHTML = productEditorLoading;
    productEditorTrigger?.focus({ preventScroll: true });
    productEditorTrigger = null;
  });

  productEditorContent?.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-admin-product-form]');
    if (!form) return;
    event.preventDefault();
    form.classList.add('was-validated');
    form.querySelectorAll('[data-admin-category-picker]').forEach((picker) => {
      updateAdminCategoryPicker(picker);
      if (!picker.querySelector('[data-admin-category-checkbox]:checked')) picker.setAttribute('open', '');
    });
    if (!form.reportValidity()) {
      form.querySelector(':invalid')?.focus({ preventScroll: false });
      return;
    }

    const submit = form.querySelector('[data-admin-product-submit]');
    const submitLabel = submit?.querySelector('span');
    const feedback = form.querySelector('[data-admin-product-form-feedback]');
    const saveStatus = form.querySelector('[data-admin-product-save-status]');
    const saveStatusLabel = saveStatus?.querySelector('[data-admin-product-save-status-label]');
    if (submit) submit.disabled = true;
    if (submitLabel) submitLabel.textContent = 'Se salvează…';
    if (saveStatusLabel) saveStatusLabel.textContent = 'Saving…';
    if (feedback) feedback.hidden = true;
    form.classList.add('is-saving');

    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      const contentType = response.headers.get('Content-Type') || '';
      if (!contentType.includes('application/json')) throw new Error('Sesiunea a expirat. Reîncarcă pagina și autentifică-te din nou.');
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.message || 'Produsul nu a putut fi salvat.');
      productEditorDialog?.classList.add('is-success');
      form.classList.remove('is-saving');
      form.classList.add('is-saved');
      setProductFormSyncState(form, 'synced');
      if (submitLabel) submitLabel.textContent = 'Salvat cu grijă';
      window.setTimeout(() => {
        closeProductEditor();
        window.setTimeout(() => window.location.reload(), reducedMotion ? 0 : 250);
      }, reducedMotion ? 80 : 720);
    } catch (error) {
      form.classList.remove('is-saving');
      setProductFormSyncState(form, 'dirty');
      if (submitLabel) submitLabel.textContent = 'Încearcă din nou';
      if (feedback) {
        feedback.textContent = error.message || 'A apărut o problemă temporară. Verifică datele și încearcă din nou.';
        feedback.hidden = false;
        feedback.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'center' });
      }
      updateProductSubmitState(form);
    }
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

  const supplierEditorDialog = document.querySelector('[data-admin-supplier-editor-dialog]');
  const supplierEditorContent = supplierEditorDialog?.querySelector('[data-admin-supplier-editor-content]');
  const supplierEditorLoading = supplierEditorContent?.innerHTML || '';
  let supplierEditorRequest = null;
  let supplierEditorTrigger = null;

  const closeSupplierEditor = () => {
    if (!supplierEditorDialog?.open) return;
    supplierEditorRequest?.abort();
    supplierEditorRequest = null;
    supplierEditorDialog.classList.remove('is-visible', 'is-success');
    document.documentElement.classList.remove('has-admin-supplier-editor');
    window.setTimeout(() => {
      if (supplierEditorDialog.open) supplierEditorDialog.close();
    }, reducedMotion ? 0 : 210);
  };

  const loadSupplierEditor = async (trigger) => {
    if (!supplierEditorDialog || !supplierEditorContent) return;
    supplierEditorRequest?.abort();
    supplierEditorRequest = new AbortController();
    supplierEditorContent.innerHTML = supplierEditorLoading;
    try {
      const response = await fetch(trigger.dataset.modalUrl, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        signal: supplierEditorRequest.signal,
      });
      const markup = await response.text();
      if (!response.ok || response.redirected || !markup.includes('data-admin-supplier-form')) {
        throw new Error('Furnizorul nu mai este disponibil sau sesiunea a expirat.');
      }
      supplierEditorContent.innerHTML = markup;
      requestAnimationFrame(() => supplierEditorContent.querySelector('input:not([type="hidden"])')?.focus({ preventScroll: true }));
    } catch (error) {
      if (error.name === 'AbortError') return;
      supplierEditorContent.innerHTML = `<div class="admin-supplier-editor-dialog__error"><strong>Nu am putut deschide fișa</strong><p>${error.message || 'A apărut o problemă temporară.'}</p></div>`;
    } finally {
      supplierEditorRequest = null;
    }
  };

  document.querySelectorAll('[data-admin-supplier-editor-trigger]').forEach((trigger) => {
    trigger.addEventListener('click', (event) => {
      if (!supplierEditorDialog || typeof supplierEditorDialog.showModal !== 'function' || !window.fetch) return;
      event.preventDefault();
      supplierEditorTrigger = trigger;
      supplierEditorDialog.showModal();
      document.documentElement.classList.add('has-admin-supplier-editor');
      requestAnimationFrame(() => supplierEditorDialog.classList.add('is-visible'));
      loadSupplierEditor(trigger);
    });
  });
  supplierEditorDialog?.addEventListener('click', (event) => {
    if (event.target === supplierEditorDialog || event.target.closest('[data-admin-supplier-editor-close]')) {
      event.preventDefault();
      closeSupplierEditor();
    }
  });
  supplierEditorDialog?.addEventListener('cancel', (event) => {
    event.preventDefault();
    closeSupplierEditor();
  });
  supplierEditorDialog?.addEventListener('close', () => {
    document.documentElement.classList.remove('has-admin-supplier-editor');
    supplierEditorDialog.classList.remove('is-visible', 'is-success');
    if (supplierEditorContent) supplierEditorContent.innerHTML = supplierEditorLoading;
    supplierEditorTrigger?.focus({ preventScroll: true });
    supplierEditorTrigger = null;
  });
  supplierEditorContent?.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-admin-supplier-form]');
    if (!form) return;
    event.preventDefault();
    if (!form.reportValidity()) return;
    const submit = form.querySelector('[data-admin-supplier-submit]');
    const label = submit?.querySelector('span');
    const feedback = form.querySelector('[data-admin-supplier-form-feedback]');
    if (submit) submit.disabled = true;
    if (label) label.textContent = 'Se salvează…';
    if (feedback) feedback.hidden = true;
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.message || 'Furnizorul nu a putut fi salvat.');
      supplierEditorDialog.classList.add('is-success');
      if (label) label.textContent = 'Salvat cu grijă';
      window.setTimeout(() => {
        closeSupplierEditor();
        window.setTimeout(() => window.location.reload(), reducedMotion ? 0 : 220);
      }, reducedMotion ? 80 : 620);
    } catch (error) {
      if (submit) submit.disabled = false;
      if (label) label.textContent = 'Încearcă din nou';
      if (feedback) {
        feedback.textContent = error.message || 'Verifică datele și încearcă din nou.';
        feedback.hidden = false;
      }
    }
  });

  const supplierDeleteDialog = document.querySelector('[data-admin-supplier-delete-dialog]');
  const supplierDeleteName = supplierDeleteDialog?.querySelector('[data-admin-supplier-delete-name]');
  const supplierDeleteConfirm = supplierDeleteDialog?.querySelector('[data-admin-supplier-delete-confirm]');
  let pendingSupplierDeleteForm = null;

  const closeSupplierDeleteDialog = () => {
    if (!supplierDeleteDialog?.open) return;
    supplierDeleteDialog.close();
  };

  document.querySelectorAll('[data-admin-supplier-delete-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      pendingSupplierDeleteForm = form;
      if (supplierDeleteName) supplierDeleteName.textContent = form.dataset.supplierName || 'Furnizorul';
      if (!supplierDeleteDialog || typeof supplierDeleteDialog.showModal !== 'function') {
        if (window.confirm(`Ștergi furnizorul „${form.dataset.supplierName || ''}”?`)) form.submit();
        return;
      }
      supplierDeleteDialog.showModal();
    });
  });
  supplierDeleteDialog?.querySelector('[data-admin-supplier-delete-cancel]')?.addEventListener('click', closeSupplierDeleteDialog);
  supplierDeleteDialog?.addEventListener('click', (event) => {
    if (event.target === supplierDeleteDialog) closeSupplierDeleteDialog();
  });
  supplierDeleteDialog?.addEventListener('cancel', (event) => {
    event.preventDefault();
    closeSupplierDeleteDialog();
  });
  supplierDeleteDialog?.addEventListener('close', () => {
    pendingSupplierDeleteForm = null;
  });
  supplierDeleteConfirm?.addEventListener('click', () => {
    if (!pendingSupplierDeleteForm) return;
    supplierDeleteConfirm.disabled = true;
    supplierDeleteConfirm.textContent = 'Se șterge…';
    pendingSupplierDeleteForm.submit();
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
