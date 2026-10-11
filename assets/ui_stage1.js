(function () {
  function ensureStyle() {
    if (document.getElementById('monibin-stage1-ui')) return;

    const style = document.createElement('style');
    style.id = 'monibin-stage1-ui';
    style.textContent = `
      .brand {
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
        padding: 0 !important;
        background: transparent !important;
        border: 0 !important;
        color: inherit !important;
        text-align: right !important;
      }

      .brand-copy {
        display: flex !important;
        flex-direction: column !important;
        align-items: flex-start !important;
        line-height: 1.1 !important;
      }

      .brand-title {
        font-size: clamp(1rem, 2vw, 1.75rem) !important;
        font-weight: 700 !important;
        letter-spacing: 0 !important;
      }

      .brand-tagline {
        font-size: 0.72rem !important;
        opacity: 0.8 !important;
        white-space: nowrap !important;
      }

      .brand-mark {
        width: 52px !important;
        height: 52px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        border-radius: 16px !important;
        background: linear-gradient(135deg, #0f766e, #14b8a6) !important;
        box-shadow: 0 12px 26px rgba(15, 118, 110, 0.16) !important;
        flex-shrink: 0 !important;
      }

      .brand-mark svg {
        width: 28px !important;
        height: 28px !important;
        display: block !important;
      }

      .top-search {
        display: flex !important;
        align-items: center !important;
        gap: 8px !important;
        background: rgba(255,255,255,0.08) !important;
        border: 1px solid rgba(255,255,255,0.16) !important;
        border-radius: 999px !important;
        padding: 4px 10px 4px 6px !important;
        min-width: min(300px, 38vw) !important;
      }

      .top-search input {
        border: 0 !important;
        outline: 0 !important;
        background: transparent !important;
        color: inherit !important;
        font: inherit !important;
        width: 100% !important;
        min-width: 120px !important;
      }

      .top-search input::placeholder {
        color: rgba(255,255,255,0.7) !important;
      }

      .top-search .navbtn,
      .mobile-menu-toggle,
      .mobile-menu-panel .navbtn {
        border-radius: 999px !important;
      }

      .mobile-menu-panel {
        position: absolute !important;
        top: calc(100% + 10px) !important;
        left: 16px !important;
        right: 16px !important;
        display: none !important;
        flex-direction: column !important;
        gap: 8px !important;
        padding: 12px !important;
        background: rgba(14, 33, 31, 0.98) !important;
        border: 1px solid rgba(255,255,255,0.08) !important;
        border-radius: 16px !important;
        box-shadow: 0 16px 40px rgba(0, 0, 0, 0.16) !important;
        z-index: 1000 !important;
      }

      .mobile-menu-panel.is-open {
        display: flex !important;
      }

      .navlinks {
        position: relative !important;
      }

      .navlinks .mobile-menu-toggle {
        display: none !important;
      }

      @media (max-width: 860px) {
        .brand {
          gap: 10px !important;
        }

        .brand-copy {
          align-items: flex-start !important;
        }

        .brand-tagline {
          white-space: normal !important;
          max-width: 140px !important;
          line-height: 1.4 !important;
        }

        .top-search {
          display: none !important;
        }

        .navlinks {
          gap: 8px !important;
          flex-wrap: wrap !important;
        }

        .navlinks .mobile-menu-toggle {
          display: inline-flex !important;
        }
      }
    `;
    document.head.appendChild(style);
  }

  function applyBrandHeader() {
    const brand = document.querySelector('.brand');
    if (!brand) return;

    const title = document.title || 'پایگاه معارف استاد حامد رنجبر';
    const tagline = document.querySelector('#brandTagline')?.textContent || 'درس‌ها و سخنرانی‌های استاد حامد رنجبر';

    brand.innerHTML = `
      <span class="brand-mark" aria-hidden="true">
        <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" fill="none">
          <path d="M18 44V20.5c0-3.7 3-6.7 6.7-6.7h14.6c3.7 0 6.7 3 6.7 6.7V44" stroke="white" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M22 30.5h20M22 39h12" stroke="white" stroke-width="4" stroke-linecap="round"/>
          <circle cx="32" cy="15" r="7" fill="white" opacity="0.9"/>
        </svg>
      </span>
      <span class="brand-copy">
        <span class="brand-title">${title}</span>
        <span class="brand-tagline">${tagline}</span>
      </span>
    `;
  }

  function toggleMobileMenu() {
    const panel = document.querySelector('.mobile-menu-panel');
    if (!panel) return;
    panel.classList.toggle('is-open');
  }

  function refreshHeaderNav() {
    const nav = document.querySelector('.navlinks');
    if (!nav) return;

    const authButton = window.state && window.state.isUser
      ? '<button class="navbtn" onclick="accountMenu()">حساب کاربری</button>'
      : '<button class="navbtn" onclick="accountModal(\'login\')">ورود</button>';

    const panelItems = `
      <button class="navbtn" onclick="showHome()">خانه</button>
      <button class="navbtn" onclick="showAnnouncements()">اعلانات</button>
      <button class="navbtn" onclick="showSessions()">جلسات</button>
      <button class="navbtn" onclick="showArticles()">متن‌ها</button>
      <button class="navbtn" onclick="showGallery()">گالری</button>
      ${window.state && window.state.isUser ? '<button class="navbtn" onclick="showFavorites()">علاقه‌مندی‌ها</button>' : ''}
      ${window.state && window.state.isAdmin ? '<button class="navbtn" onclick="manageAdmins()">مدیریت</button>' : ''}
    `;

    nav.innerHTML = `
      <button class="navbtn" onclick="showHome()">خانه</button>
      <div class="top-search">
        <input id="globalSearchInput" type="search" placeholder="جست‌وجو" aria-label="جست‌وجو" />
        <button class="navbtn" type="button" onclick="if (document.getElementById('globalSearchInput')) { const q = document.getElementById('globalSearchInput').value.trim(); if (q) { window.showHome(); window.toast && window.toast('جست‌وجو در مرحله بعدی تکمیل می‌شود.'); } }">جست‌وجو</button>
      </div>
      ${authButton}
      <button class="navbtn mobile-menu-toggle" type="button" aria-label="باز کردن منو" onclick="toggleMobileMenu()">☰</button>
      <div class="mobile-menu-panel" id="mobileMenuPanel">${panelItems}</div>
    `;
  }

  function applyVisibleTerminology() {
    const mapping = {
      'فیش': 'یادداشت',
      'فیش‌ها': 'یادداشت‌ها',
      'تگ': 'برچسب',
      'تگ‌ها': 'برچسب‌ها',
      'مجموعه جلسات': 'سلسله جلسات',
      'مجموعه': 'سلسله',
      'مطلب': 'متن',
      'مطالب': 'متن‌ها',
      'مطلب جدید': 'متن جدید',
      'مطالب جدید': 'متن‌های جدید',
      'خانه': 'خانه'
    };

    const textWalker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false);
    const textNodes = [];
    while (textWalker.nextNode()) {
      const node = textWalker.currentNode;
      const value = node.nodeValue || '';
      if (value.trim() && value.includes('فیش') || value.includes('تگ') || value.includes('مجموعه') || value.includes('مطلب') || value.includes('مطالب')) {
        textNodes.push(node);
      }
    }

    for (const node of textNodes) {
      let text = node.nodeValue || '';
      for (const [from, to] of Object.entries(mapping)) {
        text = text.split(from).join(to);
      }
      node.nodeValue = text;
    }
  }

  function ensureGuestHome() {
    if (document.getElementById('app') && !document.getElementById('app').innerHTML.trim()) {
      if (typeof window.showHome === 'function') {
        window.showHome();
      }
    }
  }

  function runStage1Ui() {
    ensureStyle();
    applyBrandHeader();
    refreshHeaderNav();
    applyVisibleTerminology();
    setTimeout(ensureGuestHome, 150);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', runStage1Ui, { once: true });
  } else {
    runStage1Ui();
  }
})();
