(() => {
  const legacy = {
    showHome: window.showHome,
    showSessions: window.showSessions,
    showArticles: window.showArticles,
    showAnnouncements: window.showAnnouncements,
    showGallery: window.showGallery,
    showTags: window.showTags,
    showTag: window.showTag,
    showFishList: window.showFishList,
    openFishPage: window.openFishPage,
    openLecturePage: window.openLecturePage,
    openArticlePage: window.openArticlePage,
    openLightbox: window.openLightbox,
    closeLightbox: window.closeLightbox,
    openFish: window.openFish,
    openArticle: window.openArticle,
    openLecture: window.openLecture,
    saveLecture: window.saveLecture,
    api: window.api,
    closeModal: window.closeModal,
    load: window.load,
    archiveSessionCard: window.archiveSessionCard
  };
  let isRouting = false;
  let lastRoute = '';
  let fishBaseRoute = '';
  let pendingFavorite = null;
  let fishModalOpen = false;
  const progressWriteAt = new Map();
  const progressWritePending = new Set();
  const progressWriteQueued = new Map();
  let bulkAudioEntries = [];
  let bulkAudioCollectionId = 0;

  window.api = function (path, options = {}) {
    if (path === 'lectures.php' && typeof options.body === 'string') {
      const payload = JSON.parse(options.body);
      if ($('mSessionText')) payload.session_text = $('mSessionText').value;
      if ($('mHijri')) payload.hijri_date = $('mHijri').value.trim();
      options = { ...options, body: JSON.stringify(payload) };
    }
    return legacy.api(path, options);
  };

  window.openLecture = function (...args) {
    legacy.openLecture(...args);
    const lecture = state.lectures?.find(item => Number(item.id) === Number(args[0]));
    const collectionId = Number(args[1] || lecture?.collection_id || 0);
    if (collectionId) addBulkAudioControls(collectionId);
    const description = $('mDesc');
    if (description && !$('mHijri')) description.closest('.field')?.insertAdjacentHTML('afterend', `<div class="field"><label for="mHijri">تاریخ قمری تأییدشده (اختیاری)</label><input id="mHijri" maxlength="100" value="${esc(lecture?.hijri_date || '')}" placeholder="مثلاً ۱۱ رمضان ۱۴۴۷"><span class="small">تاریخ قمری فقط با مقدار تأییدشده ذخیره می‌شود.</span></div>`);
    if (!description || $('mSessionText')) return;
    description.closest('.field')?.insertAdjacentHTML('afterend', `<div class="field full"><label for="mSessionText">متن کامل جلسه</label><textarea id="mSessionText" rows="10">${esc(state.lectures?.find(item => Number(item.id) === Number(args[0]))?.session_text || '')}</textarea><div class="small">متن جلسه جدا از توضیحات کوتاه ذخیره می‌شود و از Markdown پشتیبانی می‌کند.</div></div>`);
  };

  if (typeof window.updateCalText === 'function' && typeof window.confirmCalendar === 'function') {
    const updateCalendarPreview = window.updateCalText;
    const confirmCalendarSelection = window.confirmCalendar;
    window.updateCalText = function () {
      updateCalendarPreview();
      const preview = $('calSelected');
      if (preview) preview.textContent = `${preview.textContent.split('· قمری:')[0]}· قمری: پس از تأیید دستی ثبت شود`;
    };
    window.confirmCalendar = function () {
      const target = dateTarget;
      confirmCalendarSelection();
      const date = $(target);
      if (date) date.dataset.hijri = '';
      if ($(target + 'Preview')) $(target + 'Preview').textContent = 'تاریخ قمری را پس از تأیید دستی وارد کنید.';
    };
  }

  function addBulkAudioControls(collectionId) {
    if ($('bulkAudioSection')) return;
    bulkAudioEntries = [];
    bulkAudioCollectionId = collectionId;
    const anchor = $('collectionFields') || $('mAudio')?.closest('.field');
    if (!anchor) return;
    anchor.insertAdjacentHTML('afterend', `<div class="field full" id="bulkAudioSection"><label for="bulkAudioFiles">افزودن چند جلسه به این سلسله</label><input id="bulkAudioFiles" type="file" accept=".mp3,.m4a,.wav,.ogg,.aac,audio/*" multiple onchange="previewBulkAudioFiles()"><p class="small">تا ۲۰ فایل صوتی انتخاب کنید. تاریخ از نام فایل‌های دارای الگوی YYYYMMDD پیشنهاد می‌شود؛ هر مورد را پیش از ثبت بازبینی کنید.</p><div id="bulkAudioPreview"></div><div class="actions"><button class="btn primary" id="bulkAudioSave" type="button" onclick="saveBulkAudioFiles()" disabled>ثبت فایل‌های آماده</button></div></div>`);
    $('mCollection') && ($('mCollection').value = String(collectionId));
  }

  function parseBulkJalali(value) {
    const ascii = String(value || '').replace(/[۰-۹]/g, digit => '۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)).replace(/[٠-٩]/g, digit => '٠١٢٣٤٥٦٧٨٩'.indexOf(digit));
    const parts = ascii.match(/^(\d{4})[/-](\d{1,2})[/-](\d{1,2})$/);
    if (!parts) return '';
    const [, yearText, monthText, dayText] = parts;
    const year = Number(yearText), month = Number(monthText), day = Number(dayText);
    if (month < 1 || month > 12 || day < 1 || day > (month <= 6 ? 31 : month === 12 ? 30 : 30)) return '';
    const gregorian = jalaliInputToGreg(`${year}/${month}/${day}`);
    const converted = gregorian.split('-').map(Number);
    const back = g2j(converted[0], converted[1], converted[2]);
    return back[0] === year && back[1] === month && back[2] === day ? gregorian : '';
  }

  window.previewBulkAudioFiles = function () {
    const input = $('bulkAudioFiles');
    const files = [...(input?.files || [])];
    if (files.length > 20) {
      input.value = '';
      bulkAudioEntries = [];
      $('bulkAudioPreview').textContent = 'حداکثر ۲۰ فایل را در هر نوبت انتخاب کنید.';
      $('bulkAudioSave').disabled = true;
      return;
    }
    let nextNumber = Math.max(0, ...(state.lectures || []).filter(item => Number(item.collection_id) === bulkAudioCollectionId).map(item => Number(item.session_number) || 0)) + 1;
    bulkAudioEntries = files.map(file => {
      const matches = [...file.name.matchAll(/(?<!\d)(\d{8})(?!\d)/g)];
      const suggestedDate = matches.length === 1 ? `${matches[0][1].slice(0, 4)}/${matches[0][1].slice(4, 6)}/${matches[0][1].slice(6, 8)}` : '';
      const validDate = parseBulkJalali(suggestedDate) ? suggestedDate : '';
      return { file, session_date: validDate, session_number: nextNumber++, hijri_date: '', duplicate: false };
    });
    const hashes = new Set();
    for (const entry of bulkAudioEntries) {
      const key = `${entry.file.name.toLocaleLowerCase()}|${entry.file.size}|${entry.file.lastModified}`;
      entry.duplicate = hashes.has(key);
      hashes.add(key);
    }
    renderBulkAudioPreview();
  };

  function renderBulkAudioPreview() {
    $('bulkAudioPreview').innerHTML = bulkAudioEntries.length ? `<div class="table-wrap"><table><thead><tr><th>فایل</th><th>تاریخ شمسی</th><th>شماره قسمت</th><th>تاریخ قمری (اختیاری)</th></tr></thead><tbody>${bulkAudioEntries.map((entry, index) => `<tr><td>${esc(entry.file.name)}${entry.duplicate ? '<span class="small"> (فایل تکراری)</span>' : ''}</td><td><input aria-label="تاریخ شمسی" value="${esc(entry.session_date)}" placeholder="۱۴۰۵/۰۲/۱۳" onchange="updateBulkAudioEntry(${index},'session_date',this.value)"></td><td><input aria-label="شماره قسمت" type="number" min="1" value="${entry.session_number}" onchange="updateBulkAudioEntry(${index},'session_number',this.value)"></td><td><input aria-label="تاریخ قمری" value="" placeholder="تاریخ قمری تأییدشده (اختیاری)" onchange="updateBulkAudioEntry(${index},'hijri_date',this.value)"></td></tr>`).join('')}</tbody></table></div>` : '<p class="small">فایلی برای ثبت انتخاب نشده است.</p>';
    $('bulkAudioSave').disabled = bulkAudioEntries.length === 0;
  }

  window.updateBulkAudioEntry = function (index, key, value) {
    if (!bulkAudioEntries[index]) return;
    bulkAudioEntries[index][key] = key === 'session_number' ? Number(value) : value;
  };

  window.saveBulkAudioFiles = async function () {
    if (!bulkAudioEntries.length) return;
    const button = $('bulkAudioSave');
    button.disabled = true;
    button.textContent = 'در حال بارگذاری...';
    const form = new FormData();
    form.append('collection_id', String(bulkAudioCollectionId));
    form.append('metadata', JSON.stringify(bulkAudioEntries.map(entry => ({
      session_date: entry.session_date,
      date_gregorian: parseBulkJalali(entry.session_date),
      session_number: entry.session_number,
      hijri_date: entry.hijri_date
    }))));
    bulkAudioEntries.forEach(entry => form.append('audio_files[]', entry.file, entry.file.name));
    try {
      const response = await fetch('api/bulk_lectures.php', { method: 'POST', headers: { 'X-CSRF-Token': state.csrf }, body: form, credentials: 'same-origin' });
      const result = await response.json();
      if (!response.ok && response.status !== 207) throw new Error(result.error || 'ثبت گروهی انجام نشد.');
      const failed = result.results.filter(item => !item.ok);
      const successfulIndexes = new Set(result.results.filter(item => item.ok).map(item => item.index));
      bulkAudioEntries = bulkAudioEntries.filter((entry, index) => !successfulIndexes.has(index));
      toast(`${result.created} از ${result.total} جلسه ثبت شد.${failed.length ? ` ناموفق: ${failed.map(item => `${item.file}: ${item.error}`).join('؛ ')}` : ''}`);
      if (bulkAudioEntries.length) renderBulkAudioPreview();
      else {
        closeModal();
        await window.load();
      }
    } catch (error) {
      toast(`بارگذاری گروهی ناموفق بود: ${error.message}`);
    } finally {
      if ($('bulkAudioSave')) {
        $('bulkAudioSave').disabled = bulkAudioEntries.length === 0;
        $('bulkAudioSave').textContent = 'ثبت فایل‌های آماده';
      }
    }
  };

  function deviceProgress() {
    try {
      const saved = JSON.parse(localStorage.getItem('monibinLectureProgress') || '{}');
      return saved && typeof saved === 'object' && !Array.isArray(saved) ? saved : {};
    } catch (error) {
      toast(`خواندن پیشرفت محلی ناموفق بود: ${error.message}`);
      return {};
    }
  }

  function routePath() {
    return decodeURI((location.hash || '#/home').slice(1));
  }

  function navigate(path, replace = false) {
    if (isRouting) return;
    const hash = `#${path}`;
    if (location.hash === hash) return;
    const method = replace ? 'replaceState' : 'pushState';
    const routeState = { maarefRoute: true };
    if (!replace) routeState.previousRoute = location.hash || '#/home';
    history[method](routeState, '', hash);
    renderRoute();
  }

  function returnToPrevious(fallback) {
    const previous = history.state?.previousRoute;
    if (previous && previous.startsWith('#/')) {
      history.back();
      return;
    }
    history.replaceState({ maarefRoute: true }, '', fallback);
    lastRoute = '';
    renderRoute();
  }
  window.returnToPrevious = returnToPrevious;

  function renderRoute() {
    const route = routePath();
    if (route === lastRoute) return;
    lastRoute = route;
    isRouting = true;
    fishModalOpen = false;
    const parts = route.split('/').filter(Boolean);
    try {
      switch (parts[0] || 'home') {
        case 'sessions':
          legacy.showSessions(parts[1] ? Number(parts[1]) : null, parts[2] ? Number(parts[2]) : null);
          refreshSessionYears();
          break;
        case 'home':
          legacy.showHome();
          renderContinueListening();
          break;
        case 'notes': renderNotesList(); break;
        case 'texts': renderTextsLanding(); break;
        case 'session-texts': renderSessionTexts(); break;
        case 'articles': legacy.showArticles(); break;
        case 'announcements': legacy.showAnnouncements(); break;
        case 'gallery': legacy.showGallery(); break;
        case 'tags': window.showTags(); break;
        case 'tag': {
          const tag = state.tags?.find(item => Number(item.id) === Number(parts[1]));
          if (tag) renderTagPage(tag.name);
          else window.showTags();
          break;
        }
        case 'lecture': renderLecturePage(Number(parts[1])); break;
        case 'collection': renderCollectionPage(Number(parts[1])); break;
        case 'fish-list': legacy.showFishList(Number(parts[1])); break;
        case 'fish':
          fishModalOpen = true;
          renderFishModal(Number(parts[2]), Number(parts[1]));
          break;
        case 'article': renderArticlePage(Number(parts[1])); break;
        case 'favorites': renderFavoritesPage(); break;
        case 'about': renderAboutTeacher(); break;
        default: legacy.showHome();
      }
    } finally {
      isRouting = false;
      addSiteSettingsAction();
    }
  }

  window.showAboutTeacher = function () {
    navigate('/about');
  };

  window.showNotes = function () { navigate('/notes'); };
  window.showTexts = function () { navigate('/texts'); };

  function renderNotesList() {
    const notes = (state.lectures || []).flatMap(lecture => (lecture.fishes || []).map(fish => ({ lecture, fish })));
    $('app').innerHTML = `<section class="section"><div class="page"><h1>یادداشت‌ها</h1><p class="small">نکته‌ها و بحث‌های استخراج‌شده از جلسات.</p><div class="grid">${notes.map(({ lecture, fish }) => `<article class="card"><div class="meta"><span class="pill">${esc(lecture.title)}</span><span>${esc(lecture.session_date || '')}</span></div>${fish.title ? `<h2>${esc(fish.title)}</h2>` : ''}<div class="markdown-body">${window.renderLinkedText(fish.body)}</div><div class="actions"><button class="btn" onclick="openFishPage(${lecture.id},${fish.id})">مشاهده یادداشت</button></div></article>`).join('') || '<div class="empty">یادداشتی ثبت نشده است.</div>'}</div></div></section>`;
  }

  function renderTextsLanding() {
    const sessionTextCount = (state.lectures || []).filter(lecture => lecture.session_text).length;
    $('app').innerHTML = `<section class="section"><div class="page"><h1>متون</h1><p>متون علمی و پیاده‌سازی‌شده‌ی جلسات.</p><div class="grid2"><article class="card"><h2>مقالات</h2><p class="small">${faNum(state.articles?.length || 0)} مقاله</p><button class="btn primary" onclick="showArticles()">مشاهده مقالات</button></article><article class="card"><h2>متون جلسات</h2><p class="small">${faNum(sessionTextCount)} متن</p><button class="btn primary" onclick="showSessionTexts()">مشاهده متون جلسات</button></article></div></div></section>`;
  }

  window.showSessionTexts = function () { navigate('/session-texts'); };

  function renderSessionTexts() {
    const lectures = (state.lectures || []).filter(lecture => lecture.session_text);
    $('app').innerHTML = `<section class="section"><button class="btn ghost" onclick="showTexts()">← بازگشت به متون</button><div class="page"><h1>متون جلسات</h1><div class="grid">${lectures.map(lecture => `<article class="card"><div class="meta"><span>${esc(lecture.session_date || '')}</span>${lecture.collection_title ? `<span class="pill">${esc(lecture.collection_title)}</span>` : ''}</div><h2>${esc(lecture.title)}${lecture.session_number ? ` — جلسه ${faNum(lecture.session_number)}` : ''}</h2><p class="small">${esc(lecture.description || '')}</p><button class="btn primary" onclick="openLecturePage(${lecture.id})">مشاهده متن جلسه</button></article>`).join('') || '<div class="empty">متن جلسه‌ای ثبت نشده است.</div>'}</div></div></section>`;
  }

  function renderContinueListening() {
    const local = deviceProgress();
    const progress = { ...local, ...(state.isUser ? state.progress || {} : {}) };
    const entries = Object.entries(progress)
      .filter(([id, value]) => !value.is_completed && Number(value.position_seconds) >= 5 && state.lectures?.some(lecture => Number(lecture.id) === Number(id)))
      .sort((left, right) => String(right[1].updated_at || '').localeCompare(String(left[1].updated_at || '')))
      .slice(0, 3);
    if (!entries.length) return;
    const cards = entries.map(([id, saved]) => {
      const lecture = state.lectures.find(item => Number(item.id) === Number(id));
      return `<article class="card"><div class="meta"><span class="pill">${esc(lecture.collection_title || 'جلسه')}</span><span>${esc(lecture.session_date || '')}</span></div><h3>${esc(lecture.title)}</h3><p class="small">ادامه از ${formatTime(saved.position_seconds)}${saved.duration_seconds ? ` از ${formatTime(saved.duration_seconds)}` : ''}</p><button class="btn primary" onclick="resumeLecture(${Number(id)})">به گوش دادن ادامه دهید</button></article>`;
    }).join('');
    $('app').insertAdjacentHTML('afterbegin', `<section class="section"><div class="head"><h2>ادامه دهید</h2></div><div class="grid">${cards}</div></section>`);
  }

  function renderAboutTeacher() {
    const bio = state.settings?.teacher_bio || '';
    const images = [1, 2, 3].map(index => state.settings?.[`teacher_image_${index}`]);
    $('app').innerHTML = `<section class="section"><article class="page"><h1>${esc(state.settings?.teacher_name || 'استاد حامد رنجبر')}</h1><div class="teacher-portraits">${images.map((image, index) => image ? `<img src="${esc(image)}" alt="تصویر استاد ${index + 1}">` : `<div class="teacher-portrait-placeholder">جایگاه تصویر ${index + 1}</div>`).join('')}</div>${bio ? `<div class="markdown-body">${window.renderLinkedText(bio)}</div>` : '<p class="small">زندگی‌نامه هنوز توسط مدیر سایت تکمیل نشده است؛ اطلاعات تحصیلی یا سوابق تأییدنشده نمایش داده نمی‌شوند.</p>'}${state.adminRole === 'owner' ? '<button class="btn" onclick="editAboutTeacher()">ویرایش اطلاعات این صفحه</button>' : ''}</article></section>`;
  }

  window.editAboutTeacher = function () {
    if (state.adminRole !== 'owner') return;
    const bio = state.settings?.teacher_bio || '';
    modal(`<div class="modalhead"><h2>ویرایش درباره استاد</h2><button class="close" onclick="closeModal()">✕</button></div><p class="small">فقط اطلاعات تأییدشده را وارد کنید. متن با پشتیبانی از Markdown و پیوندهای داخلی نمایش داده می‌شود.</p><div class="field"><label for="teacherName">نام نمایشی</label><input id="teacherName" maxlength="200" value="${esc(state.settings?.teacher_name || 'استاد حامد رنجبر')}"></div><div class="formgrid">${[1, 2, 3].map(index => `<div class="field"><label for="teacherImage${index}">تصویر ${index} ${state.settings?.[`teacher_image_${index}`] ? '(تصویر فعلی حفظ می‌شود مگر فایل جدید انتخاب شود)' : ''}</label><input id="teacherImage${index}" type="file" accept="image/jpeg,image/png,image/webp"></div>`).join('')}</div><div class="field"><label for="teacherBio">زندگی‌نامه و سوابق تأییدشده</label><textarea id="teacherBio" rows="12" maxlength="20000">${esc(bio)}</textarea></div><div class="actions"><button class="btn primary" onclick="saveAboutTeacher()">ذخیره</button></div>`);
  };

  window.saveAboutTeacher = async function () {
    if (state.adminRole !== 'owner') return;
    try {
      const values = { teacher_name: $('teacherName').value, teacher_bio: $('teacherBio').value };
      for (const index of [1, 2, 3]) {
        const file = $(`teacherImage${index}`).files[0];
        if (file) values[`teacher_image_${index}`] = await uploadFile('profile', file);
      }
      const result = await api('settings.php', { method: 'POST', body: JSON.stringify(values) });
      state.settings = state.settings || {};
      Object.assign(state.settings, result.settings);
      closeModal();
      renderAboutTeacher();
      toast('اطلاعات صفحه ذخیره شد.');
    } catch (error) {
      toast(error.message);
    }
  };

  window.editSiteSettings = function () {
    if (state.adminRole !== 'owner') return;
    const settings = state.settings || {};
    modal(`<div class="modalhead"><h2>تنظیمات مرکزی سایت</h2><button class="close" onclick="closeModal()">✕</button></div><div class="formgrid"><div class="field"><label for="siteTitle">عنوان سایت</label><input id="siteTitle" maxlength="200" value="${esc(settings.site_title || '')}"></div><div class="field"><label for="siteTagline">شعار سایت</label><input id="siteTagline" maxlength="500" value="${esc(settings.tagline || '')}"></div><div class="field"><label for="siteDomain">دامنه اصلی HTTPS</label><input id="siteDomain" type="url" placeholder="https://example.org" value="${esc(settings.canonical_domain || '')}"></div><div class="field"><label for="siteEitaa">نشانی ایتا</label><input id="siteEitaa" type="url" value="${esc(settings.eitaa_url || '')}"></div><div class="field"><label for="siteTelegram">نشانی تلگرام</label><input id="siteTelegram" type="url" value="${esc(settings.telegram_url || '')}"></div><div class="field"><label for="footerQr">تصویر QR جایگزین (اختیاری)</label><input id="footerQr" type="file" accept="image/jpeg,image/png,image/webp"></div><div class="field full"><label for="footerText">متن فوتر</label><textarea id="footerText" rows="4" maxlength="2000">${esc(settings.footer_text || '')}</textarea></div></div><div class="actions"><button class="btn primary" onclick="saveSiteSettings()">ذخیره تنظیمات</button></div>`);
  };

  window.saveSiteSettings = async function () {
    if (state.adminRole !== 'owner') return;
    try {
      const settings = {
        site_title: $('siteTitle').value,
        tagline: $('siteTagline').value,
        canonical_domain: $('siteDomain').value,
        eitaa_url: $('siteEitaa').value,
        telegram_url: $('siteTelegram').value,
        footer_text: $('footerText').value
      };
      const qr = $('footerQr').files[0];
      if (qr) settings.footer_qr_url = await uploadFile('qr', qr);
      const result = await api('settings.php', { method: 'POST', body: JSON.stringify(settings) });
      Object.assign(state.settings, result.settings);
      applyCentralSettings();
      closeModal();
      toast('تنظیمات سایت ذخیره شد.');
    } catch (error) {
      toast(error.message);
    }
  };

  function addRouteState() {
    const path = routePath();
    if (!location.hash) history.replaceState({ maarefRoute: true }, '', '#/home');
    else history.replaceState({ maarefRoute: true }, '', location.hash);
    lastRoute = '';
    renderRoute();
  }

  function formatCounts(tag) {
    return `<div class="tag-counts"><span class="pill">${faNum(tag.fish_count || 0)} یادداشت</span><span class="pill">${faNum(tag.lecture_count || 0)} جلسه</span><span class="pill">${faNum(tag.article_count || 0)} مطلب</span></div>`;
  }

  function tagCard(tag, branchIds = new Set()) {
    const id = Number(tag.id);
    const children = (state.tags || []).filter(item => (item.parent_ids || []).includes(id));
    const canDescend = !branchIds.has(id);
    const nextBranch = new Set(branchIds);
    nextBranch.add(id);
    return `<article class="tag-tree-node"><div class="head"><button class="tag" data-tag="${esc(tag.name)}">#${esc(tag.name)}</button>${state.isAdmin ? `<button class="btn" onclick="openTag(${id})">ویرایش</button>` : ''}</div>${formatCounts(tag)}${tag.definition ? `<p class="small">${esc(tag.definition)}</p>` : ''}${children.length && canDescend ? `<div class="tag-tree-children">${children.map(child => tagCard(child, nextBranch)).join('')}</div>` : ''}</article>`;
  }

  window.showTags = function () {
    navigate('/tags');
    const tags = state.tags || [];
    const roots = tags.filter(tag => !(tag.parent_ids || []).length);
    const unvisited = tags.filter(tag => !(tag.parent_ids || []).length);
    if (!roots.length && tags.length) unvisited.push(...tags);
    $('app').innerHTML = `<section class="section"><div class="head"><h2>برچسب‌ها</h2>${state.isAdmin ? '<button class="btn primary" onclick="openTag()">+ برچسب جدید</button>' : ''}</div><p class="small">هر کارت تعداد یادداشت‌ها، جلسات و مطالب مرتبط را جداگانه نشان می‌دهد. برچسب چندوالدی در زیر هر والد به همان صفحه اصلی پیوند دارد.</p><div class="tag-tree">${(roots.length ? roots : unvisited).map(tag => tagCard(tag)).join('') || '<div class="empty">برچسبی ثبت نشده است.</div>'}</div></section>`;
  };

  function renderTagPage(name) {
    const tag = (state.tags || []).find(item => item.name === name);
    if (!tag) return window.showTags();
    const lectures = state.lectures.filter(item => item.tags?.includes(name));
    const fishes = state.lectures.flatMap(lecture => lecture.fishes.filter(fish => fish.tags?.includes(name)).map(fish => ({ fish, lecture })));
    const articles = state.articles.filter(item => item.tags?.includes(name));
    const parents = (tag.parent_ids || []).map(id => state.tags.find(item => Number(item.id) === Number(id))).filter(Boolean);
    const children = state.tags.filter(item => (item.parent_ids || []).includes(Number(tag.id)));
    const section = (title, records, render) => {
      const recent = records.slice(0, 3);
      const remainder = records.slice(3);
      return `<section class="section"><div class="head"><h2>${title} <span class="small">(${faNum(records.length)})</span></h2></div><div class="grid">${recent.map(render).join('') || `<div class="empty">موردی برای ${title} ثبت نشده است.</div>`}</div>${remainder.length ? `<details class="tag-more"><summary>سایر ${title} (${faNum(remainder.length)})</summary><div class="grid" style="margin-top:12px">${remainder.map(render).join('')}</div></details>` : ''}</section>`;
    };
    $('app').innerHTML = `<section class="section"><div class="tag-hero"><div class="small" style="color:#c5eee8">صفحه برچسب</div><h1 style="margin:4px 0 8px">#${esc(name)}</h1>${tag.definition ? `<p style="line-height:2;margin:0">${esc(tag.definition)}</p>` : ''}<div class="meta" style="color:#d8f3ef"><span>${faNum(tag.fish_count || 0)} یادداشت</span><span>${faNum(tag.lecture_count || 0)} جلسه</span><span>${faNum(tag.article_count || 0)} مطلب</span></div><div class="tags">${parents.map(item => `<button class="tag" data-tag="${esc(item.name)}">والد: #${esc(item.name)}</button>`).join('')}${children.map(item => `<button class="tag" data-tag="${esc(item.name)}">فرزند: #${esc(item.name)}</button>`).join('')}</div></div></section>${section('یادداشت‌ها', fishes, item => fishSmall(item.fish, item.lecture))}${section('جلسات', lectures, sessionCard)}${section('مطالب', articles, articleCard)}`;
  };

  function favoriteRecord(type, id) {
    return (state.favorites || []).some(item => item.content_type === type && Number(item.content_id) === Number(id));
  }

  function favoriteButton(type, id) {
    const selected = favoriteRecord(type, id);
    return `<button class="btn favorite-button ${selected ? 'is-favorite' : ''}" aria-label="${selected ? 'حذف از علاقه‌مندی‌ها' : 'افزودن به علاقه‌مندی‌ها'}" title="${selected ? 'حذف از علاقه‌مندی‌ها' : 'افزودن به علاقه‌مندی‌ها'}" onclick="favoriteToggle('${type}',${Number(id)})">${selected ? '★' : '☆'}</button>`;
  }

  function loginPrompt(type, id) {
    pendingFavorite = { type, id: Number(id) };
    modal(`<div class="modalhead"><h2>ورود به حساب کاربری</h2><button class="close" onclick="closeModal()">✕</button></div><p>برای افزودن این مورد به علاقه‌مندی‌ها، ابتدا وارد حساب کاربری خود شوید.</p><div class="actions"><button class="btn primary" onclick="accountModal('login')">ورود به حساب کاربری</button><button class="btn" onclick="closeModal();pendingFavorite=null">انصراف</button></div>`);
  }

  async function accountRequest(action, username, password) {
    const response = await fetch('api/account.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', ...(action === 'logout' ? { 'X-CSRF-Token': state.csrf || '' } : {}) },
      body: JSON.stringify({ action, username, password })
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'عملیات حساب کاربری انجام نشد.');
    return result;
  }

  window.favoriteToggle = async function (type, id) {
    if (!state.isUser) return loginPrompt(type, id);
    const exists = favoriteRecord(type, id);
    try {
      const response = await fetch('api/favorites.php', {
        method: exists ? 'DELETE' : 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf || '' },
        body: JSON.stringify({ content_type: type, content_id: Number(id) })
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || 'به‌روزرسانی علاقه‌مندی انجام نشد.');
      await window.load();
      toast(exists ? 'از علاقه‌مندی‌ها حذف شد.' : 'به علاقه‌مندی‌ها اضافه شد.');
    } catch (error) {
      toast(error.message);
    }
  };

  window.accountModal = function (mode = 'login') {
    const manager = mode === 'manager';
    const registering = mode === 'register';
    if (manager) {
      modal(`<div class="modalhead"><h2>ورود مدیر</h2><button class="close" onclick="closeModal()">✕</button></div><div class="account-tabs"><button class="btn" onclick="accountModal('login')">ورود / ثبت‌نام مخاطب</button><button class="btn primary" aria-current="page">ورود مدیر</button></div><div class="field"><label>نام کاربری مدیر</label><input id="loginUser" autocomplete="username" maxlength="100"></div><div class="field" style="margin-top:10px"><label>رمز عبور</label><input id="loginPass" type="password" autocomplete="current-password"></div><div class="actions"><button class="btn primary" onclick="login()">ورود مدیر</button></div>`);
      return;
    }
    modal(`<div class="modalhead"><h2>${registering ? 'ساخت حساب کاربری' : 'ورود به حساب کاربری'}</h2><button class="close" onclick="closeModal()">✕</button></div><div class="account-tabs"><button class="btn primary" aria-current="page">ورود / ثبت‌نام مخاطب</button><button class="btn" onclick="accountModal('manager')">ورود مدیر</button></div><div class="field"><label>نام کاربری</label><input id="accountUsername" autocomplete="username" maxlength="100"></div><div class="field" style="margin-top:10px"><label>رمز عبور ${registering ? '(حداقل ۱۰ نویسه)' : ''}</label><input id="accountPassword" type="password" autocomplete="${registering ? 'new-password' : 'current-password'}"></div><div class="actions"><button class="btn primary" onclick="submitAccount('${registering ? 'register' : 'login'}')">${registering ? 'ثبت‌نام' : 'ورود'}</button><button class="btn" onclick="accountModal('${registering ? 'login' : 'register'}')">${registering ? 'ورود به حساب موجود' : 'ساخت حساب جدید'}</button></div>`);
  };

  window.submitAccount = async function (action) {
    try {
      const result = await accountRequest(action, $('accountUsername').value, $('accountPassword').value);
      state.isUser = true;
      state.username = result.username;
      state.csrf = result.csrfToken;
      closeModal();
      await window.load();
      if (pendingFavorite) {
        const pending = pendingFavorite;
        pendingFavorite = null;
        await window.favoriteToggle(pending.type, pending.id);
      }
      toast(action === 'register' ? 'حساب کاربری ساخته شد.' : 'ورود موفق بود.');
    } catch (error) {
      toast(error.message);
    }
  };

  window.accountLogout = async function () {
    try {
      await accountRequest('logout');
      state.isUser = false;
      state.username = null;
      state.favorites = [];
      await window.load();
      toast('از حساب کاربری خارج شدید.');
    } catch (error) {
      toast(error.message);
    }
  };

  function updateAccountNavigation() {
    const nav = document.querySelector('.navlinks');
    if (!nav) return;
    nav.innerHTML = `<button class="navbtn" onclick="showHome()">خانه</button><button class="navbtn" onclick="showAnnouncements()">اعلانات</button><button class="navbtn" onclick="showSessions()">جلسات</button><button class="navbtn" onclick="showNotes()">یادداشت‌ها</button><button class="navbtn" onclick="showTexts()">متون</button><button class="navbtn" onclick="showTags()">برچسب‌ها</button><button class="navbtn" onclick="showGallery()">نگارخانه</button><button class="navbtn" onclick="${state.isUser ? 'accountMenu()' : "accountModal('login')"}">${state.isUser ? esc(state.username || 'حساب کاربری') : 'ورود / ثبت‌نام'}</button>`;
  }

  window.accountMenu = function () {
    if (!state.isUser) return window.accountModal('login');
    modal(`<div class="modalhead"><h2>حساب کاربری</h2><button class="close" onclick="closeModal()">✕</button></div><p>${esc(state.username || '')}</p><div class="actions"><button class="btn primary" onclick="closeModal();showFavorites()">علاقه‌مندی‌های من</button><button class="btn" onclick="accountLogout()">خروج از حساب</button></div>`);
  };

  function applyCentralSettings() {
    const settings = state.settings || {};
    const title = settings.site_title || 'منیبین | درس‌ها و سخنرانی‌های استاد حامد رنجبر';
    document.title = title;
    const brand = document.querySelector('.brand');
    if (brand) {
      brand.textContent = title;
      let tagline = $('brandTagline');
      if (!tagline) {
        tagline = document.createElement('span');
        tagline.id = 'brandTagline';
        tagline.className = 'brand-tagline';
        brand.insertAdjacentElement('afterend', tagline);
      }
      tagline.textContent = settings.tagline || '';
    }
    const footerTitle = document.querySelector('.footer-grid > div:first-child h3');
    if (footerTitle) footerTitle.textContent = title;
    const footerDescription = $('footerDesc');
    if (footerDescription) footerDescription.textContent = settings.footer_text || settings.site_description || '';
    const oldFooterNote = document.querySelector('.footer-grid > div:first-child small');
    if (oldFooterNote) oldFooterNote.hidden = true;
    const eitaa = $('eitaaLink');
    if (eitaa) { eitaa.href = settings.eitaa_url || 'https://eitaa.com/monibin'; eitaa.textContent = 'ایتا'; }
    const telegram = $('baleLink');
    if (telegram) { telegram.href = settings.telegram_url || 'https://telegram.me/monibin'; telegram.textContent = 'تلگرام'; }
    const qr = $('qr');
    if (qr) qr.src = settings.footer_qr_url || 'assets/monibin-eitaa-qr.svg';
  }

  function addSiteSettingsAction() {
    if (state.adminRole !== 'owner') return;
    const panel = document.querySelector('.adminbar');
    if (!panel || $('siteSettingsButton')) return;
    panel.insertAdjacentHTML('beforeend', '<div class="actions"><button class="btn" id="siteSettingsButton" onclick="editSiteSettings()">تنظیمات مرکزی سایت</button></div>');
  }

  window.manageAdmins = async function () {
    const roleLabels = { owner: 'مالک', content_manager: 'مدیر محتوا', editor: 'ویراستار فقط‌خواندنی' };
    try {
      const result = await api('admins.php');
      const rows = result.admins.map(admin => `<div class="admin-user-row"><div><strong>${esc(admin.username)}</strong><span class="small">${admin.is_active ? 'فعال' : 'غیرفعال'}</span></div><label class="field">نقش<select id="adminRole${admin.id}">${Object.entries(roleLabels).map(([role, label]) => `<option value="${role}" ${admin.role === role ? 'selected' : ''}>${label}</option>`).join('')}</select></label><label class="field">رمز موقت جدید<input id="adminPassword${admin.id}" type="password" autocomplete="new-password" placeholder="اختیاری؛ حداقل ۱۲ نویسه"></label><label class="small"><input id="adminActive${admin.id}" type="checkbox" ${admin.is_active ? 'checked' : ''}> فعال</label><button class="btn" onclick="updateAdmin(${admin.id})">ذخیره</button></div>`).join('');
      modal(`<div class="modalhead"><h2>مدیران و نقش‌ها</h2><button class="close" onclick="closeModal()">✕</button></div><div class="admin-user-list">${rows || '<p>حساب مدیری ثبت نشده است.</p>'}</div><hr><h3>افزودن مدیر</h3><div class="field"><label>نام کاربری</label><input id="newAdminUsername" maxlength="100" autocomplete="off"></div><div class="field" style="margin-top:10px"><label>رمز موقت (حداقل ۱۲ نویسه)</label><input id="newAdminPassword" type="password" autocomplete="new-password"></div><div class="field" style="margin-top:10px"><label>نقش</label><select id="newAdminRole"><option value="content_manager">مدیر محتوا</option><option value="editor">ویراستار فقط‌خواندنی</option><option value="owner">مالک</option></select></div><div class="actions"><button class="btn primary" onclick="createAdmin()">ایجاد مدیر</button></div>`);
    } catch (error) {
      toast(error.message);
    }
  };

  const renderAdminManagement = window.manageAdmins;
  window.manageAdmins = async function () {
    await renderAdminManagement();
    document.querySelector('.modalbox .actions')?.insertAdjacentHTML('beforeend', '<button class="btn" onclick="viewAdminAudit()">سابقه عملیات مدیریتی</button>');
  };

  window.viewAdminAudit = async function () {
    try {
      const result = await api('audit.php');
      const rows = result.events.map(event => `<tr><td>${esc(event.created_at)}</td><td>${esc(event.actor || 'مدیر حذف‌شده')}</td><td>${esc(event.action)}</td><td>${esc(event.target_type)} ${event.target_id ? faNum(event.target_id) : ''}</td></tr>`).join('');
      modal(`<div class="modalhead"><h2>سابقه عملیات حساس</h2><button class="close" onclick="closeModal()">✕</button></div><div class="table-wrap"><table><thead><tr><th>زمان</th><th>مدیر</th><th>عملیات</th><th>مورد</th></tr></thead><tbody>${rows || '<tr><td colspan="4">رویدادی ثبت نشده است.</td></tr>'}</tbody></table></div><div class="actions"><button class="btn" onclick="manageAdmins()">بازگشت</button></div>`);
    } catch (error) {
      toast(error.message);
    }
  };

  window.createAdmin = async function () {
    try {
      await api('admins.php', { method: 'POST', body: JSON.stringify({ action: 'create', username: $('newAdminUsername').value, password: $('newAdminPassword').value, role: $('newAdminRole').value }) });
      toast('حساب مدیر ایجاد شد؛ در ورود نخست باید رمز موقت را تغییر دهد.');
      await window.manageAdmins();
    } catch (error) {
      toast(error.message);
    }
  };

  window.updateAdmin = async function (id) {
    try {
      await api('admins.php', { method: 'POST', body: JSON.stringify({ action: 'update', id, role: $(`adminRole${id}`).value, is_active: $(`adminActive${id}`).checked, password: $(`adminPassword${id}`).value }) });
      toast('حساب مدیر به‌روزرسانی شد.');
      await window.manageAdmins();
    } catch (error) {
      toast(error.message);
    }
  };

  function contentSection(title, content, type, id, tags, buttons = '') {
    return `<section class="section"><div class="page"><div class="head"><h1>${title}</h1></div><div class="content-actions">${favoriteButton(type, id)}<button class="btn" onclick="shareContent(location.href)">اشتراک‌گذاری</button><button class="btn ghost" onclick="window.print()">چاپ / ذخیره PDF</button>${buttons}</div>${renderTags(tags || [])}<div class="markdown-body">${renderLinkedText(content || '')}</div></div></section>`;
  }

  function renderArticlePage(id) {
    const article = state.articles.find(item => Number(item.id) === Number(id));
    if (!article) return legacy.showArticles();
    $('app').innerHTML = `<section class="section"><button class="btn ghost" onclick="returnToPrevious('#/articles')">← بازگشت به مطالب</button><div class="meta"><span>${esc(article.article_date || '')}</span>${article.author ? `<span>${esc(article.author)}</span>` : ''}</div>${contentSection(esc(article.title), article.body, 'article', article.id, article.tags, state.isAdmin ? `<button class="btn" onclick="openArticle(${article.id})">ویرایش</button>` : '')}</section>`;
  }

  function sessionActions(lecture) {
    return `<div class="content-actions">${favoriteButton('lecture', lecture.id)}<button class="btn" onclick="shareContent('#/lecture/${lecture.id}')">اشتراک‌گذاری</button></div>`;
  }

  function lectureProgress(lectureId) {
    const saved = state.progress?.[lectureId] || deviceProgress()[lectureId];
    if (!saved || saved.is_completed || Number(saved.position_seconds) < 5) return '';
    const position = Number(saved.position_seconds);
    const duration = Number(saved.duration_seconds);
    return `<button class="btn ghost" onclick="resumeLecture(${lectureId})">ادامه شنیدن از ${formatTime(position)}${duration ? ` از ${formatTime(duration)}` : ''}</button>`;
  }

  function lectureForAudio(audio) {
    const source = new URL(audio.currentSrc || audio.src, location.href).href;
    return state.lectures?.find(item => new URL(item.audio_url, location.href).href === source);
  }

  async function saveLectureProgress(audio, force = false) {
    const lecture = lectureForAudio(audio);
    if (!lecture || !Number.isFinite(audio.duration) || !Number.isFinite(audio.currentTime)) return;
    const id = Number(lecture.id);
    const now = Date.now();
    if (!force && now - (progressWriteAt.get(id) || 0) < 10000) return;
    if (progressWritePending.has(id)) {
      progressWriteQueued.set(id, audio);
      return;
    }
    const value = {
      position_seconds: Math.floor(audio.currentTime),
      duration_seconds: Math.floor(audio.duration),
      is_completed: audio.duration > 0 && audio.currentTime / audio.duration >= 0.95,
      updated_at: new Date().toISOString()
    };
    progressWriteAt.set(id, now);
    state.progress = state.progress || {};
    state.progress[id] = value;
    try {
      const saved = deviceProgress();
      saved[id] = value;
      localStorage.setItem('monibinLectureProgress', JSON.stringify(saved));
    } catch (error) {
      toast(`ذخیره پیشرفت محلی ناموفق بود: ${error.message}`);
    }
    try {
      if (state.isUser) {
        progressWritePending.add(id);
        await api('progress.php', { method: 'POST', body: JSON.stringify({ lecture_id: id, ...value }) });
      } else {
        const saved = deviceProgress();
        saved[id] = value;
        localStorage.setItem('monibinLectureProgress', JSON.stringify(saved));
      }
    } catch (error) {
      toast(`ذخیره پیشرفت شنیدن ناموفق بود: ${error.message}`);
    } finally {
      progressWritePending.delete(id);
      const queuedAudio = progressWriteQueued.get(id);
      if (queuedAudio) {
        progressWriteQueued.delete(id);
        window.setTimeout(() => saveLectureProgress(queuedAudio, true), 0);
      }
    }
  }

  window.resumeLecture = async function (id) {
    const lecture = state.lectures?.find(item => Number(item.id) === Number(id));
    if (!lecture) return;
    const saved = state.progress?.[id] || deviceProgress()[id];
    if (!saved || Number(saved.position_seconds) < 5) return;
    const media = [...document.querySelectorAll('.fish-page audio')].find(audio => lectureForAudio(audio)?.id === lecture.id);
    if (!media) {
      window.openLecturePage(id);
      requestAnimationFrame(() => window.resumeLecture(id));
      return;
    }
    if (media.readyState < 1) {
      media.addEventListener('loadedmetadata', () => { media.currentTime = Math.min(Number(saved.position_seconds), Math.max(0, media.duration - 1)); media.play().catch(error => toast(error.message)); }, { once: true });
      media.load();
      return;
    }
    media.currentTime = Math.min(Number(saved.position_seconds), Math.max(0, media.duration - 1));
    try { await media.play(); } catch (error) { toast(`پخش صوت انجام نشد: ${error.message}`); }
  };

  function sessionNumberBadge(lecture) {
    return `<span class="session-number">${lecture.session_number ? faNum(lecture.session_number) : '—'}</span>`;
  }

  window.sessionCard = function (lecture) {
    return `<article class="card hoverable session-card"><div class="meta"><span class="pill">${esc(lecture.category || 'جلسه')}</span>${lecture.collection_title ? `<span class="pill">${esc(lecture.collection_title)}</span>` : ''}</div><h3>${esc(lecture.title)}</h3><div class="meta"><span>${esc(lecture.session_date || '')}</span></div>${lecture.description ? `<p class="small">${esc(lecture.description)}</p>` : ''}<div class="meta"><span class="pill">${faNum(lecture.fishes?.length || 0)} یادداشت</span></div><audio class="audio" controls preload="none" src="${esc(lecture.audio_url)}"></audio>${renderTags(lecture.tags || [])}<div class="actions">${sessionActions(lecture)}<button class="btn primary" onclick="openLecturePage(${lecture.id})">مطالعه یادداشت‌های این جلسه</button></div></article>`;
  };

  window.archiveSessionCard = function (lecture) {
    return `<article class="card hoverable session-card"><div class="meta"><span class="pill">${esc(lecture.category || 'جلسه')}</span></div><h3>${esc(lecture.title)}</h3><div class="meta"><span>${esc(lecture.session_date || '')}</span>${lecture.hijri_date ? `<span>${esc(lecture.hijri_date)}</span>` : ''}</div><div class="meta"><span class="pill">${faNum(lecture.fishes?.length || 0)} یادداشت</span></div><audio class="audio" controls preload="none" src="${esc(lecture.audio_url)}"></audio><div class="actions">${sessionActions(lecture)}<button class="btn primary" onclick="openLecturePage(${lecture.id})">مشاهده جلسه</button>${state.isAdmin ? `<button class="btn" onclick="openLecture(${lecture.id})">ویرایش</button>` : ''}</div></article>`;
  };

  window.archiveCollectionCard = function (collectionId, lectures) {
    const collection = state.collections.find(item => Number(item.id) === Number(collectionId));
    const first = lectures[0];
    const title = collection?.title || first?.collection_title || first?.title || 'مجموعه جلسات';
    const total = Number(collection?.session_count || first?.session_count || lectures.length);
    return `<div class="collection-deck"><article class="card"><div class="head"><div><div class="meta"><span class="pill">مجموعه جلسات</span><span class="pill">${faNum(total)} جلسه</span></div><h3>${esc(title)}</h3></div>${state.isAdmin ? `<button class="btn" onclick="openCollectionEdit(${Number(collectionId)})">ویرایش مجموعه</button>` : ''}</div>${collection?.description ? `<p class="small">${esc(collection.description)}</p>` : ''}<div class="collection-sessions">${lectures.map(lecture => `<article class="collection-session"><div class="meta"><span class="pill">${esc(lecture.session_date || '')}</span><span class="pill">${faNum(lecture.fishes?.length || 0)} یادداشت</span></div><h3>${esc(lecture.description ? `جلسه ${lecture.session_number ? faNum(lecture.session_number) : ''}` : lecture.title)}</h3><p class="small session-topic">${esc(lecture.description || '')}</p><audio class="audio" controls preload="none" src="${esc(lecture.audio_url)}"></audio>${renderTags(lecture.tags || [])}<div class="actions">${sessionActions(lecture)}<button class="btn primary" onclick="openLecturePage(${lecture.id})">مطالعه یادداشت‌های این جلسه</button>${state.isAdmin ? `<button class="btn" onclick="openLecture(${lecture.id})">ویرایش</button>` : ''}</div>${sessionNumberBadge(lecture)}</article>`).join('')}</div><div class="actions"><button class="btn ghost" onclick="openCollectionPage(${Number(collectionId)})">مشاهده مجموعه و تصاویر</button></div></article></div>`;
  };

  function renderLecturePage(id) {
    const lecture = state.lectures.find(item => Number(item.id) === Number(id));
    if (!lecture) return legacy.showSessions();
    const count = lecture.fishes.length;
    $('app').innerHTML = `<section class="section"><button class="btn ghost" onclick="returnToPrevious('#/sessions')">← بازگشت به جلسات</button><article class="session-block fish-page" style="margin-top:14px"><div class="meta"><span class="pill">${esc(lecture.category || 'جلسه')}</span>${lecture.collection_title ? `<span class="pill">${esc(lecture.collection_title)}</span>` : ''}<span>${esc(lecture.session_date || '')}</span>${lecture.hijri_date ? `<span>${esc(lecture.hijri_date)}</span>` : ''}</div><h1>${esc(lecture.title)}</h1><p class="small">${esc(lecture.description || '')}</p><audio class="audio" controls preload="metadata" src="${esc(lecture.audio_url)}"></audio><div class="content-actions">${lectureProgress(lecture.id)}${state.isUser ? '' : '<span class="small">پیشرفت این دسبرچسباه در همین مرورگر ذخیره می‌شود؛ برای همگام‌سازی وارد حساب شوید.</span>'}</div><div class="meta"><span class="pill">${faNum(count)} یادداشت</span></div>${sessionActions(lecture)}${renderTags(lecture.tags || [])}<div class="head"><h2>یادداشت‌های این جلسه</h2>${state.isAdmin ? `<button class="btn primary" onclick="openFish(${lecture.id})">+ افزودن یادداشت</button>` : ''}</div><div class="grid">${lecture.fishes.map(fish => fishSmall(fish, lecture)).join('') || '<div class="empty">برای این جلسه هنوز یادداشتی ثبت نشده است.</div>'}</div>${state.isAdmin ? `<div class="actions"><button class="btn" onclick="openLecture(${lecture.id})">ویرایش جلسه</button></div>` : ''}</article>${renderAlbum(lecture)}</section>`;
    if (lecture.session_text) {
      const textSection = document.createElement('section');
      textSection.className = 'session-text page';
      textSection.innerHTML = `<div class="head"><h2>متن کامل جلسه</h2><button class="btn ghost" onclick="window.print()">چاپ / ذخیره PDF</button></div><div class="markdown-body">${window.renderLinkedText(lecture.session_text)}</div>`;
      document.querySelector('.fish-page .head')?.before(textSection);
    }
  }

  function renderCollectionPage(id) {
    const collection = state.collections.find(item => Number(item.id) === Number(id));
    if (!collection) return legacy.showSessions();
    const lectures = state.lectures.filter(item => Number(item.collection_id) === Number(id)).sort((a, b) => Number(a.session_number || 0) - Number(b.session_number || 0));
    const photos = [...(collection.poster_url ? [{ url: collection.poster_url, caption: collection.title }] : []), ...lectures.flatMap(lecture => lecture.photos || [])];
    $('app').innerHTML = `<section class="section"><button class="btn ghost" onclick="returnToPrevious('#/sessions')">← بازگشت به جلسات</button><div class="page"><div class="meta"><span class="pill">مجموعه جلسات</span><span class="pill">${faNum(collection.session_count || lectures.length)} جلسه</span></div><h1>${esc(collection.title)}</h1>${collection.description ? `<p>${esc(collection.description)}</p>` : ''}${renderTags(collection.tags || [])}<div class="collection-sessions">${lectures.map(lecture => `<article class="collection-session"><div class="meta"><span>${esc(lecture.session_date || '')}</span><span class="pill">${faNum(lecture.fishes.length)} یادداشت</span></div><h3>${esc(lecture.description ? `جلسه ${lecture.session_number ? faNum(lecture.session_number) : ''}` : lecture.title)}</h3><p class="small session-topic">${esc(lecture.description || '')}</p><audio class="audio" controls preload="none" src="${esc(lecture.audio_url)}"></audio>${renderTags(lecture.tags || [])}<div class="actions">${sessionActions(lecture)}<button class="btn primary" onclick="openLecturePage(${lecture.id})">مطالعه یادداشت‌های این جلسه</button>${state.isAdmin ? `<button class="btn" onclick="openLecture(${lecture.id})">ویرایش</button>` : ''}</div>${sessionNumberBadge(lecture)}</article>`).join('') || '<div class="empty">جلسه‌ای در این مجموعه ثبت نشده است.</div>'}</div></div>${photos.length ? `<section class="section"><div class="head"><h2>تصاویر مجموعه</h2></div><div class="album">${photos.map((photo, index) => `<img src="${esc(photo.url)}" alt="${esc(photo.caption)}" onclick='openLocalLight(${JSON.stringify(photos)},${index})'>`).join('')}</div></section>` : ''}</section>`;
  }

  function resolveWikiTarget(title) {
    const normalized = title.trim().toLocaleLowerCase();
    const article = state.articles.find(item => item.title.trim().toLocaleLowerCase() === normalized);
    if (article) return { type: 'article', id: Number(article.id) };
    for (const lecture of state.lectures) {
      const fish = lecture.fishes.find(item => (item.title || '').trim().toLocaleLowerCase() === normalized);
      if (fish) return { type: 'fish', id: Number(fish.id), lectureId: Number(lecture.id) };
    }
    return null;
  }

  window.renderLinkedText = function (text) {
    const input = String(text || '');
    const inline = value => {
      let result = esc(value);
      result = result.replace(/\[\[article:(\d+)\|([^\]]+)\]\]/g, (match, id, label) => `<a class="wiki-link" href="#/article/${id}" onclick="event.preventDefault();openArticlePage(${id})">${label}</a>`);
      result = result.replace(/\[\[fish:(\d+):(\d+)\|([^\]]+)\]\]/g, (match, lectureId, id, label) => `<a class="wiki-link" href="#/fish/${lectureId}/${id}" onclick="event.preventDefault();openFishPage(${lectureId},${id})">${label}</a>`);
      result = result.replace(/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/g, (match, title, label) => {
        const target = resolveWikiTarget(title);
        if (!target) return `<span class="broken-wikilink" title="مقصد این پیوند هنوز در سایت وجود ندارد">${label || title}</span>`;
        const route = target.type === 'article' ? `#/article/${target.id}` : `#/fish/${target.lectureId}/${target.id}`;
        const action = target.type === 'article' ? `openArticlePage(${target.id})` : `openFishPage(${target.lectureId},${target.id})`;
        return `<a class="wiki-link" href="${route}" onclick="event.preventDefault();${action}">${esc(label || title)}</a>`;
      });
      result = result.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+|mailto:[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
      result = result.replace(/`([^`]+)`/g, '<code>$1</code>');
      result = result.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/__([^_]+)__/g, '<strong>$1</strong>');
      result = result.replace(/(^|[^*])\*([^*]+)\*/g, '$1<em>$2</em>').replace(/~~([^~]+)~~/g, '<del>$1</del>');
      return result;
    };
    const lines = input.replace(/\r\n?/g, '\n').split('\n');
    const output = [];
    let listType = '';
    const closeList = () => { if (listType) output.push(`</${listType}>`); listType = ''; };
    for (const line of lines) {
      const heading = line.match(/^(#{1,6})\s+(.+)$/);
      const quote = line.match(/^\s*>\s?(.*)$/);
      const unordered = line.match(/^\s*[-*+]\s+(.+)$/);
      const ordered = line.match(/^\s*\d+[.)]\s+(.+)$/);
      if (heading) { closeList(); const level = heading[1].length; output.push(`<h${level}>${inline(heading[2])}</h${level}>`); }
      else if (quote) { closeList(); output.push(`<blockquote>${inline(quote[1])}</blockquote>`); }
      else if (unordered || ordered) {
        const type = unordered ? 'ul' : 'ol';
        if (listType !== type) { closeList(); listType = type; output.push(`<${type}>`); }
        output.push(`<li>${inline((unordered || ordered)[1])}</li>`);
      } else {
        closeList();
        if (line.trim()) output.push(`<p>${inline(line)}</p>`);
      }
    }
    closeList();
    return output.join('');
  };

  window.articleCard = function (article) {
    return `<article class="card hoverable"><div class="meta"><span class="pill">مطلب</span>${article.category ? `<span>${esc(article.category)}</span>` : ''}</div><h3>${esc(article.title)}</h3><div class="meta">${article.article_date ? `<span>${esc(article.article_date)}</span>` : ''}${article.author ? `<span>${esc(article.author)}</span>` : ''}</div><p class="small">${esc(article.body.slice(0, 180))}${article.body.length > 180 ? '…' : ''}</p>${renderTags(article.tags || [])}<div class="actions">${favoriteButton('article', article.id)}<button class="btn" onclick="shareContent('#/article/${article.id}')">اشتراک‌گذاری</button><button class="btn primary" onclick="openArticlePage(${article.id})">مطالعه مطلب</button>${state.isAdmin ? `<button class="btn" onclick="openArticle(${article.id})">ویرایش</button><button class="btn danger" onclick="del('articles.php',${article.id})">حذف</button>` : ''}</div></article>`;
  };

  window.fishSmall = function (fish, lecture) {
    return `<article class="card hoverable"><div class="meta"><span class="pill">${esc(lecture.title)}</span><button class="time" onclick="playAt(${lecture.id},${fish.timestamp_seconds})">${formatTime(fish.timestamp_seconds)}</button></div>${fish.title ? `<div class="fish-title">${esc(fish.title)}</div>` : ''}<div class="fish-preview">${esc(fish.body.replace(/\[\[.*?\]\]/g, '').slice(0, 180))}</div>${renderTags(fish.tags || [])}<div class="actions">${favoriteButton('fish', fish.id)}<button class="btn" onclick="shareContent('#/fish/${lecture.id}/${fish.id}')">اشتراک‌گذاری</button><button class="btn primary" onclick="openFishPage(${fish.id},${lecture.id})">مطالعه کامل</button>${state.isAdmin ? `<button class="btn" onclick="openFish(${lecture.id},${fish.id})">ویرایش</button><button class="btn danger" onclick="del('fishes.php',${fish.id})">حذف</button>` : ''}</div></article>`;
  };

  window.announcementCard = function (announcement) {
    const poster = announcement.poster_url ? `<div class="announcement-poster"><img src="${esc(announcement.poster_url)}" alt="${esc(announcement.title)}"><div class="announcement-overlay"><h3>${esc(announcement.title)}</h3><div class="meta">${esc(announcementRangeLabel(announcement))}${announcement.event_time ? ` · ${esc(announcement.event_time)}` : ''}</div><p>${esc(announcement.body)}</p></div></div>` : `<h3>${esc(announcement.title)}</h3><p>${esc(announcement.body)}</p>`;
    return `<article class="card hoverable announcement-card"><div class="meta"><span class="pill">اعلان مراسم</span>${state.isAdmin && isAnnouncementExpired(announcement) ? '<span class="pill">بایگانی‌شده</span>' : ''}</div>${poster}<div class="meta announcement-meta">${esc(announcement.location || '')}</div>${renderTags(announcement.tags || [])}<div class="actions"><button class="btn primary" onclick="openAnnouncementPage(${announcement.id})">مشاهده</button>${state.isAdmin ? `<button class="btn" onclick="openAnnouncement(${announcement.id})">ویرایش</button><button class="btn danger" onclick="del('announcements.php',${announcement.id})">حذف</button>` : ''}</div></article>`;
  };

  function refreshSessionYears() {
    const list = document.querySelector('.year-list');
    if (!list) return;
    const current = g2j(new Date().getFullYear(), new Date().getMonth() + 1, new Date().getDate())[0];
    const years = new Set([current, ...state.lectures.map(lectureJalaliYear)]);
    list.innerHTML = [...years].filter(year => year >= 1380).sort((a, b) => b - a).map(year => `<button class="year-choice ${Number(selectedSessionYear) === year ? 'active' : ''}" onclick="showSessions(${year},${Number(selectedSessionCategory || 0)})">${faNum(year)}</button>`).join('');
  }

  window.renderArticlePage = renderArticlePage;
  window.openCollectionPage = function (id) { navigate(`/collection/${Number(id)}`); };

  window.openTagById = function (id) {
    const tag = state.tags.find(item => Number(item.id) === Number(id));
    if (tag) window.showTag(tag.name);
  };

  window.openTag = function (id = 0) {
    const tag = state.tags.find(item => Number(item.id) === Number(id)) || {};
    const selected = new Set((tag.parent_ids || (tag.parent_id ? [tag.parent_id] : [])).map(Number));
    modal(`<div class="modalhead"><h2>${id ? 'ویرایش برچسب' : 'برچسب جدید'}</h2><button class="close" onclick="closeModal()">✕</button></div><div class="field"><label>نام</label><input id="mName" value="${esc(tag.name || '')}"></div><div class="field" style="margin-top:10px"><label>والدها (امکان انتخاب چند مورد)</label><select id="mParents" multiple size="7">${state.tags.filter(item => Number(item.id) !== Number(id)).map(item => `<option value="${Number(item.id)}" ${selected.has(Number(item.id)) ? 'selected' : ''}>${esc(item.name)}</option>`).join('')}</select></div><div class="field" style="margin-top:10px"><label>تعریف برچسب</label><textarea id="mDef">${esc(tag.definition || '')}</textarea></div><div class="actions"><button class="btn primary" onclick="saveTag(${Number(id)})">ذخیره</button></div>`);
  };

  window.saveTag = async function (id) {
    try {
      const parentIds = [...$('mParents').selectedOptions].map(option => Number(option.value));
      await api('tags.php', { method: id ? 'PUT' : 'POST', body: JSON.stringify({ id, name: $('mName').value, parent_ids: parentIds, definition: $('mDef').value }) });
      closeModal();
      await window.load();
      toast('برچسب ذخیره شد.');
    } catch (error) { toast(error.message); }
  };

  window.openFish = function (lectureId, id = 0) {
    const lecture = state.lectures.find(item => Number(item.id) === Number(lectureId));
    if (!lecture) return toast('جلسه موردنظر پیدا نشد.');
    const fish = lecture.fishes.find(item => Number(item.id) === Number(id)) || {};
    modal(`<div class="modalhead"><h2>${id ? 'ویرایش یادداشت' : 'یادداشت جدید'}</h2><button class="close" onclick="closeModal()">✕</button></div><div class="formgrid"><div class="field"><label>جلسه</label><div class="selected-date">${esc(lecture.title)}</div></div><div class="field"><label>عنوان یادداشت (اختیاری)</label><input id="mTitle" value="${esc(fish.title || '')}"></div><div class="field"><label>زمان اتصال به جلسه</label><input id="mTime" value="${formatTime(fish.timestamp_seconds || 0)}" placeholder="مثلاً 4:26"></div>${tagInput(fish.tags || [])}<div class="field full"><label>متن یادداشت (Markdown)</label><textarea id="mBody">${esc(fish.body || '')}</textarea><div class="markdown-help">برای پیوند به مطلب یا یادداشت دیگر، عنوان مقصد را داخل <code>[[ ]]</code> بنویسید؛ نمونه: <code>[[عنوان یادداشت مقصد|متن نمایشی]]</code>.</div></div></div><div class="actions"><button class="btn" onclick="closeModal()">انصراف</button><button class="btn primary" onclick="saveFish(${lecture.id},${Number(id)})">ذخیره</button></div>`);
  };

  window.openArticle = function (id = 0) {
    const article = state.articles.find(item => Number(item.id) === Number(id)) || {};
    modal(`<div class="modalhead"><h2>${id ? 'ویرایش مطلب' : 'مطلب جدید'}</h2><button class="close" onclick="closeModal()">✕</button></div><div class="formgrid"><div class="field"><label>عنوان</label><input id="mTitle" value="${esc(article.title || '')}"></div><div class="field"><label>دسته</label><select id="mCat">${catOptions('article', article.category_id)}</select></div><div class="field"><label>نویسنده</label><input id="mAuthor" value="${esc(article.author || '')}"></div><div class="field"><label>تاریخ شمسی</label><input id="mDate" readonly value="${esc(article.article_date || '')}" data-greg="${esc(jalaliInputToGreg(article.article_date || ''))}" data-hijri="${esc(article.hijri_date || '')}" onclick="openDatePicker('mDate')" placeholder="انتخاب تاریخ"></div>${tagInput(article.tags || [])}<div class="field full"><label>متن مطلب (Markdown)</label><textarea id="mBody" style="min-height:280px">${esc(article.body || '')}</textarea><div class="markdown-help">برای پیوند به مطلب یا یادداشت دیگر، از <code>[[عنوان مقصد]]</code> یا <code>[[عنوان مقصد|متن نمایشی]]</code> استفاده کنید.</div></div></div><div class="actions"><button class="btn" onclick="closeModal()">انصراف</button><button class="btn primary" onclick="saveArticle(${Number(id)})">ذخیره</button></div>`);
  };

  window.shareContent = async function (url) {
    const settings = state.settings || {};
    const domain = settings.canonical_domain || location.origin;
    const resolved = new URL(url, location.href);
    const shareUrl = new URL(resolved.pathname + resolved.search + resolved.hash, domain).href;
    const contentTitle = document.querySelector('#app h1, #app h2')?.textContent?.trim() || settings.site_title || document.title;
    const signature = `${settings.site_title || 'منیبین | درس‌ها و سخنرانی‌های استاد حامد رنجبر'}${settings.tagline ? `\n${settings.tagline}` : ''}\nنشانی سایت: ${domain}\nمشاهده این محتوا: ${shareUrl}`;
    try {
      if (navigator.share) await navigator.share({ title: contentTitle, text: signature, url: shareUrl });
      else if (navigator.clipboard) { await navigator.clipboard.writeText(`${contentTitle}\n${signature}`); toast('متن کامل اشتراک‌گذاری کپی شد.'); }
      else toast(signature);
    } catch (error) {
      if (error.name !== 'AbortError') toast('اشتراک‌گذاری انجام نشد.');
    }
  };

  window.showFavorites = function () { navigate('/favorites'); };
  function renderFavoritesPage() {
    if (!state.isUser) {
      $('app').innerHTML = '<section class="page"><h1>علاقه‌مندی‌ها</h1><p>برای مشاهده موارد ذخیره‌شده وارد حساب کاربری شوید.</p><button class="btn primary" onclick="accountModal(\'login\')">ورود</button></section>';
      return;
    }
    const rows = (state.favorites || []).map(item => {
      const id = Number(item.content_id);
      let title = '';
      let action = '';
      if (item.content_type === 'lecture') {
        const lecture = state.lectures.find(record => Number(record.id) === id);
        if (!lecture) return '';
        title = lecture.title;
        action = `openLecturePage(${id})`;
      } else if (item.content_type === 'article') {
        const article = state.articles.find(record => Number(record.id) === id);
        if (!article) return '';
        title = article.title;
        action = `openArticlePage(${id})`;
      } else {
        for (const lecture of state.lectures) {
          const fish = lecture.fishes.find(record => Number(record.id) === id);
          if (fish) { title = fish.title || `یادداشت ${formatTime(fish.timestamp_seconds)}`; action = `openFishPage(${id},${lecture.id})`; break; }
        }
        if (!action) return '';
      }
      return `<article class="card account-favorite-row"><div><span class="pill">${item.content_type === 'fish' ? 'یادداشت' : item.content_type === 'lecture' ? 'جلسه' : 'مطلب'}</span><h3>${esc(title)}</h3></div><div class="actions"><button class="btn primary" onclick="${action}">مشاهده</button>${favoriteButton(item.content_type, id)}</div></article>`;
    }).join('');
    $('app').innerHTML = `<section class="section"><div class="head"><h1>علاقه‌مندی‌های ${esc(state.username || '')}</h1></div><div class="grid">${rows || '<div class="empty">هنوز موردی ذخیره نشده است.</div>'}</div></section>`;
  }

  window.closeModal = function () {
    if (state.mustChangePassword) return;
    if (fishModalOpen) {
      fishModalOpen = false;
      if (fishBaseRoute && history.state?.maarefRoute) {
        history.back();
      } else {
        const route = fishBaseRoute || '#/home';
        history.replaceState({ maarefRoute: true }, '', route);
        lastRoute = '';
        renderRoute();
      }
      return;
    }
    legacy.closeModal();
  };

  window.closeLightbox = function () {
    if (history.state?.lightbox) {
      history.back();
      return;
    }
    legacy.closeLightbox();
    document.body.classList.remove('lightbox-open');
  };
  window.openLightbox = function (index) {
    legacy.openLightbox(index);
    document.body.classList.add('lightbox-open');
    if (!history.state?.lightbox) history.pushState({ maarefRoute: true, lightbox: true, previousRoute: location.hash || '#/home' }, '', location.href);
  };

  window.load = async function () {
    try {
      const response = await fetch('api/data.php');
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || 'بارگذاری اطلاعات سایت انجام نشد.');
      Object.assign(state, result);
      state.adminSignedIn = !!state.isAdmin;
      state.isAdmin = state.adminSignedIn && state.adminRole !== 'editor';
      document.body.classList.toggle('is-admin', state.adminSignedIn);
      applyCentralSettings();
      updateAccountNavigation();
      lastRoute = '';
      renderRoute();
      addSiteSettingsAction();
      if (state.adminSignedIn && state.mustChangePassword) openPasswordChange();
    } catch (error) {
      toast(error.message);
    }
  };

  window.showSessions = function (year = null, category = null) {
    if (year !== null) selectedSessionYear = Number(year);
    if (category !== null) selectedSessionCategory = Number(category);
    const y = Number(selectedSessionYear || g2j(new Date().getFullYear(), new Date().getMonth() + 1, new Date().getDate())[0]);
    navigate(`/sessions/${y}/${Number(selectedSessionCategory || 0)}`);
  };

  window.showHome = function () { navigate('/home'); };
  window.goHome = window.showHome;
  window.showArticles = function () { navigate('/articles'); };
  window.showAnnouncements = function () { navigate('/announcements'); };
  window.showGallery = function () { navigate('/gallery'); };
  window.showFavorites = function () { navigate('/favorites'); };
  window.showTag = function (name) {
    const tag = state.tags?.find(item => item.name === name || String(item.id) === String(name));
    if (tag) navigate(`/tag/${Number(tag.id)}`);
    else window.showTags();
  };
  window.showFishList = function (id) { navigate(`/fish-list/${Number(id)}`); };
  window.openFishPage = function (fishId, lectureId) {
    if (!isRouting) {
      fishBaseRoute = location.hash || '#/home';
      navigate(`/fish/${Number(lectureId)}/${Number(fishId)}`);
    } else {
      fishModalOpen = true;
      renderFishModal(fishId, lectureId);
    }
  };
  window.openArticlePage = function (id) { navigate(`/article/${Number(id)}`); };
  window.openLecturePage = function (id) { navigate(`/lecture/${Number(id)}`); };

  function renderFishModal(fishId, lectureId) {
    const lecture = state.lectures.find(item => Number(item.id) === Number(lectureId));
    const fish = lecture?.fishes.find(item => Number(item.id) === Number(fishId));
    if (!fish) { toast('یادداشت موردنظر پیدا نشد.'); return; }
    modal(`<div class="modalhead"><h2>${esc(fish.title || 'یادداشت')}</h2><button class="close" onclick="closeModal()">✕</button></div><div class="meta"><span class="pill">${esc(lecture.title)}</span><button class="time" onclick="playAt(${lecture.id},${fish.timestamp_seconds})">پخش از ${formatTime(fish.timestamp_seconds)}</button></div><div class="content-actions">${favoriteButton('fish', fish.id)}<button class="btn" onclick="shareContent(location.href)">اشتراک‌گذاری</button></div>${renderTags(fish.tags || [])}<div class="fish-full markdown-body">${renderLinkedText(fish.body)}</div><div class="actions"><button class="btn" onclick="closeModal()">بستن</button>${state.isAdmin ? `<button class="btn" onclick="editFishFromView(${lecture.id},${fish.id})">ویرایش</button>` : ''}</div>`);
  }
  window.editFishFromView = function (lectureId, fishId) {
    window.closeModal();
    setTimeout(() => window.openFish(lectureId, fishId), 80);
  };

  window.addEventListener('popstate', event => {
    if (event.state?.lightbox) {
      if (!document.body.classList.contains('lightbox-open')) {
        legacy.openLightbox(lightIndex);
        document.body.classList.add('lightbox-open');
      }
      return;
    }
    if (document.body.classList.contains('lightbox-open')) {
      legacy.closeLightbox();
      document.body.classList.remove('lightbox-open');
      lastRoute = routePath();
      return;
    }
    renderRoute();
  });
  window.addEventListener('hashchange', renderRoute);
  document.addEventListener('click', event => {
    const tagButton = event.target.closest('.tag[data-tag]');
    if (!tagButton) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    window.showTag(tagButton.dataset.tag);
  }, true);
  for (const eventName of ['timeupdate', 'pause', 'ended']) {
    document.addEventListener(eventName, event => {
      if (event.target instanceof HTMLAudioElement) saveLectureProgress(event.target, eventName !== 'timeupdate');
    }, true);
  }
  const saveVisibleAudioProgress = () => document.querySelectorAll('audio').forEach(audio => saveLectureProgress(audio, true));
  window.addEventListener('pagehide', saveVisibleAudioProgress);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') saveVisibleAudioProgress();
  });

  addRouteState();
  window.load();
})();
