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
    const enabledKey = 'la-mariuca-admin-browser-notifications';
    const newestUnread = document.querySelector('[data-admin-notification-item]:not(.is-read)');
    const setButtonState = (state) => {
      browserNotificationButton.classList.toggle('is-active', state === 'active');
      if (!label) return;
      label.textContent = state === 'active' ? 'Alerte active' : (state === 'blocked' ? 'Alerte blocate' : (state === 'unsupported' ? 'Indisponibil' : 'Activează alertele'));
    };
    const showNewest = () => {
      if (!newestUnread || Notification.permission !== 'granted') return;
      const notificationKey = newestUnread.dataset.notificationKey || '';
      const shownKey = `la-mariuca-admin-alert-${notificationKey}`;
      try {
        if (!notificationKey || window.localStorage.getItem(shownKey) === '1') return;
        const notification = new Notification(newestUnread.dataset.notificationTitle || 'Notificare La Măriuca', {
          body: newestUnread.dataset.notificationDetail || 'Ai un eveniment nou în magazin.',
          icon: document.querySelector('link[rel="icon"]')?.href || undefined,
          tag: notificationKey,
        });
        notification.onclick = () => {
          window.focus();
          if (newestUnread.dataset.notificationTarget) window.location.href = newestUnread.dataset.notificationTarget;
          notification.close();
        };
        window.localStorage.setItem(shownKey, '1');
      } catch (_) {}
    };

    if (!('Notification' in window)) {
      setButtonState('unsupported');
      browserNotificationButton.disabled = true;
    } else {
      let enabled = false;
      try { enabled = window.localStorage.getItem(enabledKey) === '1'; } catch (_) {}
      if (Notification.permission === 'denied') {
        setButtonState('blocked');
      } else if (enabled && Notification.permission === 'granted') {
        setButtonState('active');
        showNewest();
      }
      browserNotificationButton.addEventListener('click', async () => {
        if (Notification.permission === 'denied') {
          setButtonState('blocked');
          return;
        }
        const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
        if (permission !== 'granted') {
          setButtonState(permission === 'denied' ? 'blocked' : 'idle');
          return;
        }
        try { window.localStorage.setItem(enabledKey, '1'); } catch (_) {}
        setButtonState('active');
        showNewest();
      });
    }
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
