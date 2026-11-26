/**
 * =======================================================
 * Сеть магазинов разливного пива «Пивляндия» (Симферополь)
 * =======================================================
 *
 * СОДЕРЖАНИЕ
 *   1.  Состояние приложения
 *   2.  Инициализация (DOMContentLoaded)
 *   3.  18+ гейт
 *   4.  Пользователь: сессия и авторизация
 *   5.  Каталог: фильтрация и пагинация
 *   6.  Каталог: двухступенчатый тап и модалка товара
 *   7.  Каталог: свайпы в модалке товара
 *   8.  Каталог: сворачиватель описания
 *   9.  Корзина
 *   10. Оформление заказа
 *   11. Магазины и Яндекс.Карта
 *   12. Hero-слайдшоу
 *   13. Модальные окна и хелперы
 *   14. Визуальные эффекты (пузырьки, буквы, скролл)
 *   15. Бургер-меню
 *   16. Кнопка «наверх»
 *   17. Трекер поведения
 */

/* =========================================================
   1. СОСТОЯНИЕ ПРИЛОЖЕНИЯ
   ========================================================= */
const state = {
    currentCategory: null,
    categories: window.initialCategories || [],
    snacks: window.initialSnacks || [],
    products: window.initialProducts || [],
    combos: window.initialCombos || [],
    shops: [],
    cart: JSON.parse(localStorage.getItem('pivlyandiya_cart') || '[]'),
    user: null,
    yandexMap: null,
    mapPlacemarks: []
};

/* =========================================================
   2. ИНИЦИАЛИЗАЦИЯ
   ========================================================= */
document.addEventListener('DOMContentLoaded', () => {
    initBubbles();
    initAgeGate();
    initDescriptionToggles();
    initApp();
    initEvents();
    initCart();
    initProductCardClick();
    initProductDetailGestures();
    initHeroSlideshow();
    initLetterAnimation();
    initScrollAnimations();
    initTracker();
    runBadgeSequence();
});

async function initApp() {
    initFilterSplit();     // Разделяем фильтры на категории и атрибуты (сразу, до сети)
    initCategoryFilter();  // Применяем фильтр из URL (если он есть)
    await checkCurrentUser();
    await loadShops();     // Магазины грузим через API для карты
}

/* =========================================================
   3. 18+ ГЕЙТ
   ========================================================= */
function initAgeGate() {
    const isAgeConfirmed = localStorage.getItem('pivlyandiya_age_confirmed');
    const ageModal = document.getElementById('age-modal');
    if (!isAgeConfirmed && ageModal) {
        ageModal.classList.add('active');
    }
}

function confirmAge(allowed) {
    const ageModal = document.getElementById('age-modal');
    
    if (allowed) {
        localStorage.setItem('pivlyandiya_age_confirmed', 'true');
        if (ageModal) ageModal.classList.remove('active');
        trackEventBeacon({ action: 'age_confirm' });
    } else {
        trackEventBeacon({ action: 'age_decline' });
        
        // Вместо перехода на Гугл — меняем окно на сообщение об отказе
        if (ageModal) {
            const modalBox = ageModal.querySelector('.modal-box');
            if (modalBox) {
                modalBox.innerHTML = `
                    <div style="text-align: center; padding: 10px 0;">
                        <div style="font-size: 4rem; color: #ef4444; margin-bottom: 20px;">
                            <i class="fa-solid fa-ban"></i>
                        </div>
                        <h2 style="font-size: 1.5rem; margin-bottom: 16px; color: #ef4444; font-weight: 800; text-transform: uppercase;">Доступ запрещен</h2>
                        <p style="color: var(--text-secondary); font-size: 0.95rem; margin-bottom: 30px; line-height: 1.5;">
                            Продажа алкогольной продукции лицам, не достигшим 18 лет, запрещена. <br><br>
                            <strong>Пожалуйста, возвращайтесь, когда вам исполнится 18 лет!</strong>
                        </p>
                        <button class="btn btn-secondary btn-lg" style="width: 100%;" onclick="window.location.reload()">
                            Я ошибся (Мне есть 18)
                        </button>
                    </div>
                `;
            }
        }
    }
}

/* =========================================================
   4. ПОЛЬЗОВАТЕЛЬ: СЕССИЯ И АВТОРИЗАЦИЯ
   ========================================================= */
async function checkCurrentUser() {
    try {
        const res = await fetch('index.php?api=current_user');
        const data = await res.json();
        if (data.success && data.logged_in) {
            state.user = data.user;
            updateUserUI();
        }
    } catch (e) {
        console.warn('API сессии недоступен', e);
    }
}

function updateUserUI() {
    const authBtn = document.getElementById('auth-btn');
    if (!authBtn) return;

    if (state.user) {
        if (state.user.role === 'admin') {
            authBtn.innerHTML = `<i class="fa-solid fa-shield-halved"></i> Админка`;
            authBtn.onclick = () => window.location.href = 'admin.php';
        } else {
            authBtn.innerHTML = `<i class="fa-solid fa-user-check"></i> ${state.user.name || 'Кабинет'}`;
            authBtn.onclick = showUserMenu;
        }
    } else {
        authBtn.innerHTML = `<i class="fa-solid fa-right-to-bracket"></i> Войти`;
        authBtn.onclick = () => openModal('auth-modal');
    }
}

async function handleAuthSubmit(e) {
    e.preventDefault();
    const login = document.getElementById('auth-login').value;
    const password = document.getElementById('auth-password').value;
    const errorEl = document.getElementById('auth-error');

    try {
        const res = await fetch('index.php?api=auth', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ login, password })
        });
        const data = await res.json();

        if (data.success) {
            state.user = data.user;
            closeModal('auth-modal');
            updateUserUI();

            if (data.role === 'admin') {
                showToast('Добро пожаловать в админ-панель!');
                setTimeout(() => {
                    window.location.href = 'admin.php';
                }, 500);
            } else {
                showToast(`Здравствуйте, ${login}! Вы вошли как покупатель.`);
            }
        } else {
            if (errorEl) {
                errorEl.textContent = data.message || 'Ошибка авторизации';
                errorEl.style.display = 'block';
            }
        }
    } catch (err) {
        console.error('Ошибка входа:', err);
    }
}

function showUserMenu() {
    if (confirm(`Вы вошли как ${state.user?.name || 'Пользователь'}.\nХотите выйти из профиля?`)) {
        fetch('index.php?api=logout').then(() => {
            state.user = null;
            updateUserUI();
            showToast('Вы вышли из системы');
        });
    }
}

/* =========================================================
   5. КАТАЛОГ: ФИЛЬТРАЦИЯ И ПАГИНАЦИЯ (4 → +8 → +8 ...)
   ========================================================= */
const INITIAL_VISIBLE = 4;
const LOAD_STEP = 8;
let visibleCount = INITIAL_VISIBLE;

function applyCategoryFilter(catValue, updateUrl = true, resetPagination = true) {
    state.currentCategory = catValue;
    if (resetPagination) visibleCount = INITIAL_VISIBLE;

    let catId = 0;
    if (catValue) {
        const btn = Array.from(document.querySelectorAll('.category-tab')).find(b => b.dataset.category === catValue);
        if (btn) catId = parseInt(btn.dataset.catId || 0, 10);
    }

    const attrSection = document.querySelector('.filter-section[data-section-type="attributes"]');
            if (attrSection) {
                attrSection.style.display = catId > 0 ? '' : 'none';
            }

            const attrSelect = document.getElementById('attr-filter-select');
    if (attrSelect) {
        while (attrSelect.options.length > 1) {
            attrSelect.remove(1);
        }
    }

    // Подсветка активной вкладки и фильтрация вкладок атрибутов
    document.querySelectorAll('.category-tab').forEach(tab => {
        const tabCat = tab.dataset.category || null;
        tab.classList.toggle('active', tabCat === catValue);
        
        if (tab.dataset.type === 'attribute') {
            const tabCatId = parseInt(tab.dataset.catId || 0, 10);
            const isHidden = (catId > 0 && tabCatId > 0 && tabCatId !== catId);
            if (isHidden) {
                tab.style.display = 'none';
            } else {
                tab.style.display = '';
                const sel = document.getElementById('attr-filter-select');
                if (sel) {
                    const opt = document.createElement('option');
                    opt.value = tab.dataset.category || '';
                    opt.textContent = (tab.textContent || '').replace(/\s+/g, ' ').trim();
                    sel.appendChild(opt);
                }
            }
        }
    });

    const attrSelSync = document.getElementById('attr-filter-select');
    if (attrSelSync) {
        const aval = catValue || '';
        const hasAopt = Array.from(attrSelSync.options).some(o => o.value === aval);
        attrSelSync.value = hasAopt ? aval : '';
    }

    // Синхронизация выпадающего списка категорий
    const catSelect = document.getElementById('category-filter-select');
    if (catSelect) {
        let targetCatVal = '';
        if (catValue) {
            if (Array.from(catSelect.options).some(o => o.value === catValue)) {
                targetCatVal = catValue;
            } else {
                const attrBtn = Array.from(document.querySelectorAll('.category-tab')).find(b => b.dataset.category === catValue && b.dataset.type === 'attribute');
                if (attrBtn) {
                    const cid = parseInt(attrBtn.dataset.catId || 0, 10);
                    if (cid > 0) {
                        const catBtn = Array.from(document.querySelectorAll('.category-tab')).find(b => b.dataset.type === 'category' && parseInt(b.dataset.catId || 0, 10) === cid);
                        if (catBtn) targetCatVal = catBtn.dataset.category || '';
                    }
                }
            }
        }
        catSelect.value = targetCatVal;
    }

    // Показ/скрытие карточек по тегам
    const cards = document.querySelectorAll('#products-grid .product-card');
    let shown = 0;
    let totalMatching = 0;

    cards.forEach(card => {
        const cardTags = (card.dataset.category || '').split(',').map(s => s.trim()).filter(Boolean);
        const matches = !catValue || cardTags.includes(catValue);
        if (matches) {
            totalMatching++;
            if (shown < visibleCount) {
                card.style.display = '';
                shown++;
            } else {
                card.style.display = 'none';
            }
        } else {
            card.style.display = 'none';
        }
    });

    // Кнопка «Показать еще»
    const btn = document.getElementById('load-more-btn');
    if (btn) btn.style.display = totalMatching > visibleCount ? 'inline-flex' : 'none';

    // URL — ?filter=<значение>
    if (updateUrl) {
        const url = catValue
            ? `?filter=${encodeURIComponent(catValue)}`
            : window.location.pathname;
        window.history.pushState({ filterValue: catValue }, '', url);
    }
}

function selectCategory(catId) {
    applyCategoryFilter(catId, true, true);
    document.getElementById('catalog-section')?.scrollIntoView({ behavior: 'smooth' });
}

function loadMoreProducts() {
    visibleCount += LOAD_STEP;
    applyCategoryFilter(state.currentCategory, false, false);
}

function initFilterSplit() {
    const container = document.getElementById('category-filter-container');
    if (!container || container.dataset.splitDone === '1') return;
    const allBtns = Array.from(container.querySelectorAll('.category-tab'));
    if (allBtns.length === 0) return;

    const catNames = new Set();
    (window.initialCategories || []).forEach(c => { if (c && c.name) catNames.add(c.name); });

    const catButtons = [];
    const attrButtons = [];
    allBtns.forEach(btn => {
        const val = btn.dataset.category || '';
        (val === '' || catNames.has(val) ? catButtons : attrButtons).push(btn);
    });

    const activeBtn = allBtns.find(b => b.classList.contains('active'));
    const activeVal = activeBtn ? (activeBtn.dataset.category || '') : '';

    container.innerHTML = '';
    container.classList.remove('category-filter');

    function makeSection(label, icon, buttons, selectId, placeholder, sectionType) {
        const wrap = document.createElement('div');
        wrap.className = 'filter-section';
        wrap.dataset.sectionType = sectionType;

        const lbl = document.createElement('div');
        lbl.className = 'filter-label';
        const ic = document.createElement('i');
        ic.className = 'fa-solid ' + icon;
        lbl.appendChild(ic);
        lbl.appendChild(document.createTextNode(' ' + label));
        wrap.appendChild(lbl);

        const chips = document.createElement('div');
        chips.className = 'category-filter filter-chips';
        buttons.forEach(function (btn) { chips.appendChild(btn); });
        wrap.appendChild(chips);

        const sel = document.createElement('select');
        sel.id = selectId;
        sel.className = 'filter-select';
        const opt0 = document.createElement('option');
        opt0.value = '';
        opt0.textContent = placeholder;
        sel.appendChild(opt0);
        if (sectionType === 'categories') {
            buttons.forEach(function (btn) {
                const opt = document.createElement('option');
                opt.value = btn.dataset.category || '';
                opt.textContent = (btn.textContent || '').replace(/\s+/g, ' ').trim();
                sel.appendChild(opt);
            });
        }
        sel.addEventListener('change', function () {
            selectCategory(this.value || null);
        });
        wrap.appendChild(sel);
        return wrap;
    }

    container.appendChild(makeSection('Категории', 'fa-layer-group', catButtons, 'category-filter-select', 'Все товары', 'categories'));
    if (attrButtons.length > 0) {
        container.appendChild(makeSection('Характеристики', 'fa-tags', attrButtons, 'attr-filter-select', 'Все характеристики', 'attributes'));
    }

    container.dataset.splitDone = '1';
    applyCategoryFilter(activeVal || null, false, false);
}

function initCategoryFilter() {
    const urlParams = new URLSearchParams(window.location.search);
    const filter = urlParams.get('filter');
    applyCategoryFilter(filter || null, false, true);
}

// Кнопки «Назад/Вперед» в браузере
window.addEventListener('popstate', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const filter = urlParams.get('filter');
    applyCategoryFilter(filter || null, false, true);
});

/* =========================================================
   6. КАТАЛОГ: ДВУХСТУПЕНЧАТЫЙ ТАП + МОДАЛКА ТОВАРА
   ========================================================= */
let detailProductList = [];
let detailIndex = 0;

function escapeHtml(str) {
    return String(str == null ? '' : str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function initProductCardClick() {
    const grid = document.getElementById('products-grid');
    if (!grid) return;

    grid.addEventListener('click', (e) => {
        // Только мобильные/планшеты — на десктопе всё видно и без модалки
        if (!window.matchMedia('(max-width: 768px)').matches) return;

        // Клики по кнопкам/ссылкам внутри карточки обрабатываются как раньше
        if (e.target.closest('button, a')) return;

        const card = e.target.closest('.product-card');
        if (!card) return;

        const id = card.dataset.productId;
        if (!id) return;

        // Второй тап по той же карточке — открываем подробности
        if (card.classList.contains('is-tapped')) {
            openProductDetail(id);
            resetCardActions();
            return;
        }

        // Первый тап — раскрываем карточку и показываем кнопку «Открыть»
        resetCardActions();
        card.classList.add('is-tapped');

        if (!card.querySelector('.product-open-btn')) {
            const btn = document.createElement('button');
            btn.className = 'product-open-btn';
            btn.type = 'button';
            btn.innerHTML = '<i class="fa-solid fa-arrow-up-right-from-square"></i> Открыть';
            btn.addEventListener('click', (ev) => {
                ev.stopPropagation();
                openProductDetail(id);
                resetCardActions();
            });
            const content = card.querySelector('.product-content');
            if (content) content.appendChild(btn);
        }
    });
}

function resetCardActions() {
    document.querySelectorAll('#products-grid .product-card.is-tapped')
        .forEach(c => c.classList.remove('is-tapped'));
}

function openProductDetail(productId) {
    // Переход на отдельную страницу товара
    window.location.href = 'product.php?id=' + encodeURIComponent(productId);
}

function showNextProduct() {
    if (detailProductList.length < 2) return;
    detailIndex = (detailIndex + 1) % detailProductList.length;
    renderProductDetail(true, 'next');
}

function showPrevProduct() {
    if (detailProductList.length < 2) return;
    detailIndex = (detailIndex - 1 + detailProductList.length) % detailProductList.length;
    renderProductDetail(true, 'prev');
}

function renderProductDetail(animate = false, direction = null) {
    const p = detailProductList[detailIndex];
    if (!p) return;

    const content = document.getElementById('product-detail-content');
    if (!content) return;

    const spec = (label, value, suffix) => {
        if (value === null || value === undefined || value === '') return '';
        const txt = escapeHtml(String(value)) + (suffix || '');
        return `<div class="spec-item"><span>${label}</span><strong>${txt}</strong></div>`;
    };

    const specs = [
        spec('Алкоголь', p.alcohol, p.alcohol ? ' %' : ''),
        spec('Плотность', p.density, p.density ? ' %' : ''),
        spec('IBU', p.ibu, ''),
        spec('Сочетается с', p.pairing, '')
    ].filter(Boolean).join('');

    const pricesEnabled = window.pricesEnabled !== false;
    const price = Number(p.price || 0).toLocaleString('ru-RU');
    const addBtn = window.cartEnabled !== false
        ? `<button class="btn btn-primary btn-lg" onclick="addToCart(${Number(p.id)}, 'product'); closeModal('product-detail-modal');">
               <i class="fa-solid fa-cart-plus"></i> В корзину
           </button>`
        : '';

    const manufacturerHtml = p.manufacturer
        ? `<div class="pd-manufacturer">${escapeHtml(p.manufacturer)}</div>`
        : '';

    const pdFallback = p.no_photo || ((p.category_name || '').toLowerCase().includes('рыб')
        ? 'images/no-photo-fish.webp'
        : 'images/no-photo.webp');
    const html = `
        <div class="pd-image-box">
            <img src="${escapeHtml(p.image || pdFallback)}"
                 alt="${escapeHtml(p.name)}"
                 class="pd-image"
                 onerror="this.src='${pdFallback}'">
            ${manufacturerHtml}
            <span class="product-badge">${escapeHtml(p.category_name || 'Разливное')}</span>
            <span class="product-volume-tag">${escapeHtml(p.volume || '1.0 л')}</span>
        </div>
        <div class="pd-body">
            <h3 class="pd-title">${escapeHtml(p.name)}</h3>
            <p class="pd-desc">${escapeHtml(p.description || '')}</p>
            ${specs ? `<div class="pd-specs">${specs}</div>` : ''}
            <div class="pd-footer">
                ${pricesEnabled ? `<div class="product-price-box">
                    <span class="product-price-label">Цена за 1 л</span>
                    <span class="product-price">${price} ₽</span>
                </div>` : ''}
                ${addBtn}
            </div>
        </div>
    `;

    const DURATION = 280;
    const EASING = 'cubic-bezier(0.4, 0, 0.2, 1)';

    if (animate && direction) {
        // Снимок уходящего контента
        const outgoing = content.cloneNode(true);
        outgoing.removeAttribute('id');
        outgoing.style.position = 'absolute';
        outgoing.style.top = '0';
        outgoing.style.left = '0';
        outgoing.style.right = '0';
        outgoing.style.bottom = '0';
        outgoing.style.pointerEvents = 'none';
        outgoing.style.zIndex = '5';
        outgoing.style.background = 'var(--bg-card)';

        // modal-box уже position: relative и overflow: hidden — просто добавим снимок
        content.parentNode.appendChild(outgoing);

        // Обновляем контент новым товаром
        content.innerHTML = html;
        content.scrollTop = 0;

        const delta = direction === 'next' ? 1 : -1;

        // Старый уезжает
        outgoing.animate(
            [
                { transform: 'translateX(0)' },
                { transform: `translateX(${-delta * 100}%)` }
            ],
            { duration: DURATION, easing: EASING, fill: 'forwards' }
        ).onfinish = () => outgoing.remove();

        // Новый приезжает
        content.animate(
            [
                { transform: `translateX(${delta * 100}%)` },
                { transform: 'translateX(0)' }
            ],
            { duration: DURATION, easing: EASING }
        );
    } else {
        content.innerHTML = html;
        content.scrollTop = 0;
    }
}

/* =========================================================
   7. КАТАЛОГ: СВАЙПЫ В МОДАЛКЕ ТОВАРА
   ========================================================= */
function initProductDetailGestures() {
    const modal = document.getElementById('product-detail-modal');
    if (!modal) return;
    const box = modal.querySelector('.product-detail-modal-box');
    if (!box) return;

    let startX = 0, startY = 0, startTime = 0;
    let tracking = false;
    let lockedAxis = null; // 'x' | 'y' | null

    const getScrollEl = () => document.getElementById('product-detail-content');

    const isAtTop = () => {
        const el = getScrollEl();
        return !el || el.scrollTop <= 0;
    };
    const isAtBottom = () => {
        const el = getScrollEl();
        if (!el) return true;
        return el.scrollTop + el.clientHeight >= el.scrollHeight - 2;
    };

    box.addEventListener('touchstart', (e) => {
        if (e.touches.length !== 1) { tracking = false; return; }
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        startTime = Date.now();
        tracking = true;
        lockedAxis = null;
    }, { passive: true });

    box.addEventListener('touchcancel', () => {
        tracking = false;
        lockedAxis = null;
    });

    // Не passive — потому что preventDefault
    box.addEventListener('touchmove', (e) => {
        if (!tracking) return;
        const t = e.touches[0];
        if (!t) return;

        const dx = t.clientX - startX;
        const dy = t.clientY - startY;
        const absX = Math.abs(dx);
        const absY = Math.abs(dy);

        if (!lockedAxis) {
            const THRESHOLD = 10;
            if (absX > THRESHOLD && absX > absY) lockedAxis = 'x';
            else if (absY > THRESHOLD && absY > absX) lockedAxis = 'y';
            else return;
        }

        if (lockedAxis === 'x') {
            // Горизонтальный свайп — листание, страницу не скроллим
            e.preventDefault();
            return;
        }

        if (lockedAxis === 'y') {
            // Вертикальный свайп. Если контент можно скроллить — отдаём скролл браузеру.
            if (dy > 0 && !isAtTop()) return;      // вниз, но контент не в самом верху
            if (dy < 0 && !isAtBottom()) return;   // вверх, но контент не в самом низу
            // Иначе это закрывающий жест — блокируем прокрутку страницы под модалкой
            e.preventDefault();
        }
    }, { passive: false });

    box.addEventListener('touchend', (e) => {
        if (!tracking) return;
        tracking = false;

        const t = e.changedTouches[0];
        if (!t) return;

        const dx = t.clientX - startX;
        const dy = t.clientY - startY;
        const dt = Date.now() - startTime;
        const absX = Math.abs(dx);
        const absY = Math.abs(dy);

        if (dt > 700) return;
        const SWIPE_MIN = 50;

        if (absX > absY && absX > SWIPE_MIN) {
            if (dx < 0) showNextProduct();
            else showPrevProduct();
            return;
        }

        if (absY > absX && absY > SWIPE_MIN) {
            if (dy > 0 && !isAtTop()) return;
            if (dy < 0 && !isAtBottom()) return;
            closeModal('product-detail-modal');
        }
    }, { passive: true });
}

/* =========================================================
   8. КАТАЛОГ: СВОРАЧИВАТЕЛЬ ОПИСАНИЯ
   ========================================================= */
function initDescriptionToggles() {
    document.querySelectorAll('#products-grid .product-desc').forEach(desc => {
        const toggle = desc.nextElementSibling;
        if (!toggle || !toggle.classList.contains('desc-toggle')) return;

        const style = window.getComputedStyle(desc);
        const lineHeight = parseFloat(style.lineHeight);
        const fontSize = parseFloat(style.fontSize);
        const realLineHeight = isNaN(lineHeight) ? fontSize * 1.2 : lineHeight;

        const hiddenHeight = desc.scrollHeight - desc.clientHeight;

        if (hiddenHeight > realLineHeight * 1.5) {
            toggle.classList.add('visible');
        } 
        else if (hiddenHeight > 2) {
            desc.classList.add('expanded');
        }
    });
}

function toggleDescription(btn) {
    const desc = btn.previousElementSibling;
    if (!desc || !desc.classList.contains('product-desc')) return;

    const isExpanded = desc.classList.toggle('expanded');
    btn.classList.toggle('expanded', isExpanded);

    const textEl = btn.querySelector('.desc-toggle-text');
    if (textEl) textEl.textContent = isExpanded ? 'свернуть' : 'развернуть';
}

/* =========================================================
   9. КОРЗИНА
   ========================================================= */
function initCart() {
    updateCartBadge();
    renderCartDrawer();
}

function addToCart(itemId, type = 'product') {
    if (window.cartEnabled === false) {
        alert('Корзина временно отключена администратором.');
        return;
    }

    let itemData = null;
    if (type === 'product') {
        itemData = state.products.find(p => p.id == itemId);
    } else if (type === 'combo') {
        itemData = state.combos.find(c => c.id == itemId);
    } else if (type === 'snack') {
        itemData = state.snacks.find(s => s.id == itemId);
    }

    if (!itemData) return;

    const existingIndex = state.cart.findIndex(i => i.id == itemId && i.type === type);
    if (existingIndex > -1) {
        state.cart[existingIndex].qty += 1;
    } else {
        const isFish = type === 'product'
            && (itemData.category_name || '').toLowerCase().includes('рыб');
        state.cart.push({
            id: itemData.id,
            type: type,
            name: itemData.name,
            price: Number(itemData.price || 0),
            image: itemData.image,
            fallback: itemData.no_photo || (isFish ? 'images/no-photo-fish.webp' : 'images/no-photo.webp'),
            qty: 1,
            unit: type === 'product'
                ? (itemData.volume || '1.0 л')
                : (type === 'snack' ? (itemData.weight || '100 г') : 'набор')
        });
    }

    saveCart();
    showToast(`«${itemData.name}» добавлен в корзину!`);
    toggleCartDrawer(true);
}

function changeCartQty(index, delta) {
    if (!state.cart[index]) return;
    state.cart[index].qty += delta;
    if (state.cart[index].qty <= 0) {
        state.cart.splice(index, 1);
    }
    saveCart();
}

function removeCartItem(index) {
    state.cart.splice(index, 1);
    saveCart();
}

function saveCart() {
    localStorage.setItem('pivlyandiya_cart', JSON.stringify(state.cart));
    updateCartBadge();
    renderCartDrawer();
}

function updateCartBadge() {
    const totalItems = state.cart.reduce((sum, item) => sum + item.qty, 0);
    const badges = document.querySelectorAll('.cart-count');
    badges.forEach(b => b.textContent = totalItems);
}

function renderCartDrawer() {
    const container = document.getElementById('cart-items-container');
    const totalEl = document.getElementById('cart-total-price');
    const orderBtn = document.getElementById('cart-order-btn');
    const pricesEnabled = window.pricesEnabled !== false;
    if (!container) return;

    if (state.cart.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 40px 10px; color: var(--text-muted);">
                <i class="fa-solid fa-basket-shopping" style="font-size: 2.5rem; margin-bottom: 12px; color: var(--gold-dark);"></i>
                <p>Ваша корзина пуста</p>
                <button class="btn btn-outline btn-sm" style="margin-top: 14px;" onclick="toggleCartDrawer(false)">Выбрать напитки</button>
            </div>
        `;
        if (totalEl) totalEl.textContent = '0 ₽';
        if (orderBtn) orderBtn.disabled = true;
        return;
    }

    let total = 0;
    container.innerHTML = state.cart.map((item, idx) => {
        const itemSum = item.price * item.qty;
        total += itemSum;
        return `
            <div class="cart-item">
                <img src="${item.image}" alt="${item.name}" class="cart-item-img" onerror="this.src='${item.fallback || 'images/no-photo.webp'}'" />
                <div class="cart-item-info">
                    <div class="cart-item-title">${item.name}</div>
                    ${pricesEnabled ? `<div class="cart-item-price">${item.price} ₽ × ${item.qty} = <strong>${itemSum} ₽</strong></div>` : ''}
                </div>
                <div class="cart-qty-controls">
                    <button class="cart-qty-btn" onclick="changeCartQty(${idx}, -1)">-</button>
                    <span style="font-weight: 700; font-size: 0.9rem;">${item.qty}</span>
                    <button class="cart-qty-btn" onclick="changeCartQty(${idx}, 1)">+</button>
                    <button class="cart-qty-btn" style="color: #ef4444;" onclick="removeCartItem(${idx})"><i class="fa-solid fa-trash"></i></button>
                </div>
            </div>
        `;
    }).join('');

    if (totalEl && pricesEnabled) totalEl.textContent = `${total} ₽`;
    if (orderBtn) orderBtn.disabled = false;
}

function toggleCartDrawer(open) {
    const drawer = document.getElementById('cart-drawer');
    const overlay = document.getElementById('cart-drawer-overlay');
    if (!drawer) return;
    if (open) {
        drawer.classList.add('active');
        overlay?.classList.add('active');
    } else {
        drawer.classList.remove('active');
        overlay?.classList.remove('active');
    }
}

/* =========================================================
   10. ОФОРМЛЕНИЕ ЗАКАЗА
   ========================================================= */
async function submitOrder(e) {
    e.preventDefault();
    if (state.cart.length === 0) return;

    const name = document.getElementById('order-name')?.value || 'Гость';
    const phone = document.getElementById('order-phone')?.value || '';
    const shopId = document.getElementById('order-shop')?.value || 1;

    try {
        const res = await fetch('index.php?api=order', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                name,
                phone,
                shop_id: shopId,
                items: state.cart,
                total: state.cart.reduce((sum, i) => sum + i.price * i.qty, 0)
            })
        });

        const data = await res.json();
        if (data.success) {
            state.cart = [];
            saveCart();
            toggleCartDrawer(false);
            closeModal('checkout-modal');
            alert(`🎉 Заказ #${data.order_id} успешно принят!\n\nСпасибо за покупку в «Пивляндии»! Мы подготовим ваш заказ к выбранному времени.`);
        }
    } catch (e) {
        alert('Заказ успешно зафиксирован! Продавец свяжется с вами.');
        state.cart = [];
        saveCart();
        toggleCartDrawer(false);
    }
}

function populateCheckoutShops() {
    const select = document.getElementById('order-shop');
    if (!select) return;
    select.innerHTML = state.shops.map(s => `
        <option value="${s.id}">${s.name} (${s.address})</option>
    `).join('');
}

function showCheckoutModal() {
    toggleCartDrawer(false);
    openModal('checkout-modal');
    populateCheckoutShops();
}

/* =========================================================
   11. МАГАЗИНЫ И ЯНДЕКС.КАРТА
   ========================================================= */
async function loadShops() {
    try {
        const res = await fetch('index.php?api=shops');
        const data = await res.json();
        if (data.success) {
            state.shops = data.data;
            initYandexMap();
        }
    } catch (e) {
        console.error('Ошибка загрузки магазинов:', e);
    }
}

function initYandexMap() {
    if (typeof ymaps === 'undefined') {
        const script = document.createElement('script');
        script.src = 'https://api-maps.yandex.ru/2.1/?lang=ru_RU';
        script.onload = () => {
            ymaps.ready(setupYandexMapInstance);
        };
        script.onerror = () => {
            renderMapFallback();
        };
        document.head.appendChild(script);
    } else {
        ymaps.ready(setupYandexMapInstance);
    }
}

function setupYandexMapInstance() {
    const mapEl = document.getElementById('yandex-map');
    if (!mapEl) return;

    // Жёстко задаём центр Симферополя и комфортный зум
    state.yandexMap = new ymaps.Map('yandex-map', {
        center: [44.952117, 34.102417],
        zoom: 10,
        controls: ['zoomControl', 'geolocationControl', 'fullscreenControl']
    });

    state.shops.forEach(shop => {
        const lat = parseFloat(shop.lat);
        const lng = parseFloat(shop.lng);

        if (isNaN(lat) || isNaN(lng)) return;

        // Кастомная метка
        const customIcon = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(`
            <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24">
                <path d="M17 4h-2V3c0-.6-.4-1-1-1H6C4.3 2 3 3.3 3 5v12c0 2.8 2.2 5 5 5h4c2.8 0 5-2.2 5-5v-3h2c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm-2 13c0 1.7-1.3 3-3 3H8c-1.7 0-3-1.3-3-3V5c0-.6.4-1 1-1h8v13zm2-4h-2V6h2v7z" fill="#10b981"/>
                <circle cx="9" cy="8" r="1.5" fill="#0c0e12"/>
                <circle cx="12" cy="11" r="1" fill="#0c0e12"/>
                <circle cx="8" cy="13" r="1" fill="#0c0e12"/>
                <path d="M17 4h-2V3c0-.6-.4-1-1-1H6C4.3 2 3 3.3 3 5v12c0 2.8 2.2 5 5 5h4c2.8 0 5-2.2 5-5v-3h2c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm-2 13c0 1.7-1.3 3-3 3H8c-1.7 0-3-1.3-3-3V5c0-.6.4-1 1-1h8v13zm2-4h-2V6h2v7z"
                      fill="none" stroke="#ef4444" stroke-width="1.5"
                      stroke-dasharray="80" stroke-dashoffset="80">
                    <animate attributeName="stroke-dashoffset" from="80" to="-80" dur="4s" repeatCount="indefinite" />
                </path>
            </svg>
        `);

        const placemark = new ymaps.Placemark([lat, lng], {
            balloonContentHeader: `<strong style="color: #d97706;">${shop.name}</strong>`,
            balloonContentBody: `
                <p style="margin: 0 0 5px 0;"><strong>Адрес:</strong> ${shop.address}</p>
                <p style="margin: 0 0 5px 0;"><strong>Время:</strong> ${shop.working_hours}</p>
                <p style="margin: 0 0 5px 0;"><strong>Телефон:</strong> <a href="tel:${shop.phone}" style="color: #d97706;">${shop.phone}</a></p>
                <p style="color: #10b981; font-weight: 600; margin: 0 0 12px 0;">✓ Свежий розлив в наличии</p>

                <!-- Кнопка навигатора -->
                <a href="https://yandex.ru/maps/?rtext=~${lat},${lng}&rtt=auto" target="_blank" style="display: block; text-align: center; padding: 10px; background: #f59e0b; border-radius: 8px; font-weight: bold; color: #000; text-decoration: none; transition: 0.3s;" onmouseover="this.style.background='#fbbf24'" onmouseout="this.style.background='#f59e0b'">
                    <i class="fa-solid fa-route"></i> Построить маршрут
                </a>
            `,
            hintContent: shop.name
        }, {
            iconLayout: 'default#image',
            iconImageHref: customIcon,
            iconImageSize: [28, 28],
            iconImageOffset: [-14, -28]
        });

        state.yandexMap.geoObjects.add(placemark);
        state.mapPlacemarks.push({ id: Number(shop.id), placemark });
    });
}

function renderMapFallback() {
    const mapEl = document.getElementById('yandex-map');
    if (!mapEl) return;
    mapEl.innerHTML = `
        <div style="height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; background: #151821; color: var(--text-secondary); text-align: center; padding: 20px;">
            <i class="fa-solid fa-map-location-dot" style="font-size: 3rem; color: var(--gold-primary); margin-bottom: 16px;"></i>
            <h3>7 магазинов «Пивляндия» в Симферополе</h3>
            <p>Все адреса, телефоны и графики работы указаны в списке слева.</p>
        </div>
    `;
}

/* =========================================================
   12. HERO-СЛАЙДШОУ (случайный порядок)
   ========================================================= */
function initHeroSlideshow() {
    const container = document.getElementById('hero-slideshow');
    if (!container) return;

    const slides = Array.from(container.querySelectorAll('img'));
    if (slides.length === 0) return;

    const isBroken = s => s.complete && s.naturalWidth === 0;
    const isValid  = s => s.complete && s.naturalWidth > 0 && s.style.display !== 'none';
    const pickValid = () => slides.map((s, i) => ({ s, i })).filter(({ s }) => isValid(s)).map(({ i }) => i);
    const randomFrom = arr => arr[Math.floor(Math.random() * arr.length)];

    let current = slides.findIndex(s => s.classList.contains('active'));
    if (current < 0) current = 0;

    const setActive = (idx) => {
        slides.forEach(s => s.classList.remove('active'));
        slides[idx].classList.add('active');
    };

    // Если активная картинка битая/не загрузилась — меняем на случайную валидную
    const ensureActiveIsValid = () => {
        const activeIdx = slides.findIndex(s => s.classList.contains('active'));
        if (activeIdx !== -1 && !isBroken(slides[activeIdx])) return;
        const valid = pickValid();
        if (valid.length === 0) return;
        current = randomFrom(valid);
        setActive(current);
    };

    // На load только проверяем, что стартовый (случайно выбранный в PHP) кадр не битый;
    // повторно рандомить не нужно — иначе будет «прыжок» сразу после загрузки
    window.addEventListener('load', () => {
        ensureActiveIsValid();
        const activeIdx = slides.findIndex(s => s.classList.contains('active'));
        current = activeIdx >= 0 ? activeIdx : 0;
    });

    if (slides.length < 2) return;

    const INTERVAL = 4000;
    setInterval(() => {
        ensureActiveIsValid();

        // Кандидаты — валидные кадры, кроме текущего (без повтора «сам на себя»)
        const candidates = pickValid().filter(i => i !== current);
        if (candidates.length === 0) return;

        current = randomFrom(candidates);
        setActive(current);
    }, INTERVAL);
}

/* =========================================================
   13. МОДАЛЬНЫЕ ОКНА И ХЕЛПЕРЫ
   ========================================================= */
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.add('active');
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.remove('active');
}

function initEvents() {
    // Закрытие модалок по клику на фон (в index.php для них стоит защита)
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                overlay.classList.remove('active');
            }
        });
    });
}

function showToast(message) {
    let toast = document.getElementById('site-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'site-toast';
        toast.style.position = 'fixed';
        toast.style.bottom = '24px';
        toast.style.right = '24px';
        toast.style.background = 'linear-gradient(135deg, #1e2430, #14171f)';
        toast.style.border = '1px solid #f59e0b';
        toast.style.color = '#fff';
        toast.style.padding = '14px 22px';
        toast.style.borderRadius = '12px';
        toast.style.boxShadow = '0 10px 25px rgba(0,0,0,0.8), 0 0 15px rgba(245,158,11,0.3)';
        toast.style.zIndex = '9999';
        toast.style.fontWeight = '600';
        toast.style.transition = 'all 0.3s ease';
        document.body.appendChild(toast);
    }
    toast.innerHTML = `<i class="fa-solid fa-beer-mug-empty" style="color: #fbbf24; margin-right: 8px;"></i> ${message}`;
    toast.style.opacity = '1';
    toast.style.transform = 'translateY(0)';

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(20px)';
    }, 3000);
}

/* =========================================================
   14. ВИЗУАЛЬНЫЕ ЭФФЕКТЫ
   ========================================================= */

/* --- 14.1. Белые пузырьки на фоне --- */
function initBubbles() {
    const container = document.querySelector('.bg-bubbles');
    if (!container) return;

    // На мобильных делаем меньше пузырьков, чтобы не грузить процессор
    const isMobile = window.innerWidth < 768;
    const count = isMobile ? 24 : 60;

    for (let i = 0; i < count; i++) {
        const li = document.createElement('li');

        const size = Math.random() * 18 + 4;   // случайный размер 4–22px
        const left = Math.random() * 100;      // случайная позиция по горизонтали
        const duration = Math.random() * 15 + 10; // скорость 10–25с
        const delay = Math.random() * 15;      // случайная задержка

        li.style.width = `${size}px`;
        li.style.height = `${size}px`;
        li.style.left = `${left}%`;
        li.style.animationDuration = `${duration}s`;
        li.style.animationDelay = `-${delay}s`; // отрицательная задержка раскидывает их сразу

        container.appendChild(li);
    }
}

/* --- 14.2. Анимация появления текста по буквам --- */
function initLetterAnimation() {
    const h1 = document.querySelector('.hero-animated-title');
    if (!h1) return;
    let totalChars = 0;

    function processNode(parent) {
        Array.from(parent.childNodes).forEach(node => {
            if (node.nodeType === 3) { // Текстовый узел
                const text = node.textContent;
                const words = text.split(/(\s+)/); // Разделяем по пробелам
                const fragment = document.createDocumentFragment();

                words.forEach(word => {
                    if (word.trim() === '') {
                        fragment.appendChild(document.createTextNode(word));
                    } else {
                        // Оборачиваем слово в span, чтобы оно не рвалось
                        const wordSpan = document.createElement('span');
                        wordSpan.style.display = 'inline-block';
                        wordSpan.style.whiteSpace = 'nowrap';

                        for (let i = 0; i < word.length; i++) {
                            const charSpan = document.createElement('span');
                            charSpan.textContent = word[i];
                            charSpan.style.display = 'inline-block';
                            charSpan.style.opacity = '0';
                            charSpan.style.animation = `letterReveal 0.5s cubic-bezier(0.4, 0, 0.2, 1) forwards`;
                            charSpan.style.animationDelay = `${totalChars * 0.03}s`;
                            wordSpan.appendChild(charSpan);
                            totalChars++;
                        }
                        fragment.appendChild(wordSpan);
                    }
                });
                parent.replaceChild(fragment, node);
            } else if (node.nodeType === 1 && node.tagName !== 'BR') { // Вложенный span
                processNode(node);
            }
        });
    }

    processNode(h1);
}

/* --- 14.3. Каскадное появление элементов при скролле --- */
function initScrollAnimations() {
    const elements = document.querySelectorAll('.product-card, .combo-card, .section-header, .shop-item');
    if (!elements.length) return;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                observer.unobserve(entry.target); // Анимируем только один раз
            }
        });
    }, { threshold: 0.1 }); // Срабатывает, когда элемент появляется на 10%

    elements.forEach((el, index) => {
        el.classList.add('fade-in-up');
        // Делаем задержку для эффекта «каскада» (по 4 карточки в ряд)
        el.style.transitionDelay = `${(index % 4) * 0.1}s`;
        observer.observe(el);
    });
}

/* =========================================================
   15. БУРГЕР-МЕНЮ
   ========================================================= */
function toggleMobileMenu() {
    const nav = document.querySelector('.nav-links');
    const overlay = document.getElementById('mobile-menu-overlay');
    if (nav) nav.classList.toggle('active');
    if (overlay) overlay.classList.toggle('active');
}

// Закрываем меню при клике на любую ссылку внутри него
document.querySelectorAll('.nav-links .nav-link').forEach(link => {
    link.addEventListener('click', () => {
        const nav = document.querySelector('.nav-links');
        if (nav && nav.classList.contains('active')) {
            toggleMobileMenu();
        }
    });
});

/* =========================================================
   16. КНОПКА «НАВЕРХ»
   ========================================================= */
window.addEventListener('scroll', () => {
    const btn = document.getElementById('scroll-top-btn');
    if (!btn) return;

    if (window.scrollY > 300) {
        btn.classList.add('show');
    } else {
        btn.classList.remove('show');
    }
});

/* =========================================================
   17. ТРЕКЕР ПОВЕДЕНИЯ
   ========================================================= */
const TRACKER = {
    endpoint: 'track.php',
    sessionId: null,
    startTime: Date.now(),
    heartbeatTimer: null
};

function getVisitSessionId() {
    try {
        const stored = JSON.parse(localStorage.getItem('pivlyandiya_visit') || '{}');
        const now = Date.now();
        if (stored.id && stored.expires > now) return stored.id;
        const id = (crypto.randomUUID ? crypto.randomUUID()
                  : 'v-' + now.toString(36) + '-' + Math.random().toString(36).slice(2, 10));
        localStorage.setItem('pivlyandiya_visit', JSON.stringify({ id, expires: now + 30 * 60 * 1000 }));
        return id;
    } catch { return 'v-' + Date.now(); }
}

// Обычный fetch — для случая, когда нужен ответ (получить session_id)
function trackEventAsync(payload) {
    payload.session_id = TRACKER.sessionId;
    payload.page = location.pathname + location.search;
    return fetch(TRACKER.endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        keepalive: true
    }).then(r => r.json()).catch(() => null);
}

// sendBeacon — для кликов/ухода/heartbeat (переживает закрытие вкладки)
function trackEventBeacon(payload) {
    if (!TRACKER.sessionId) return;
    payload.session_id = TRACKER.sessionId;
    payload.page = location.pathname + location.search;
    const body = JSON.stringify(payload);
    if (navigator.sendBeacon) {
        navigator.sendBeacon(TRACKER.endpoint, new Blob([body], { type: 'application/json' }));
    } else {
        fetch(TRACKER.endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: body, keepalive: true
        }).catch(() => {});
    }
}

function initTracker() {
    TRACKER.sessionId = getVisitSessionId();

    // 1) Первое событие + фиксируем session_id на сервере
    trackEventAsync({ action: 'page_view' });

    // 2) Возвратный посетитель, который уже подтверждал 18+
    if (localStorage.getItem('pivlyandiya_age_confirmed') === 'true') {
        trackEventBeacon({ action: 'age_confirm' });
    }

    // 3) Heartbeat — обновляет last_seen_at и duration_seconds
    TRACKER.heartbeatTimer = setInterval(() => {
        if (document.visibilityState === 'visible') {
            trackEventBeacon({ action: 'heartbeat' });
        }
    }, 30000);

    // При уходе во вкладку/сворачивании — тоже heartbeat
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            trackEventBeacon({ action: 'heartbeat' });
        }
    });

    // 4) Клики по интерактиву
    document.addEventListener('click', (e) => {
        const el = e.target.closest('button, a, .btn, .category-tab, [onclick]');
        if (!el) return;
        const text = (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 200);
        trackEventBeacon({
            action: 'click',
            tag: el.tagName.toLowerCase(),
            text: text,
            href: el.getAttribute('href') || ''
        });
    }, true);

    // 5) Закрытие вкладки/уход
    const sendUnload = () => {
        const duration = Math.round((Date.now() - TRACKER.startTime) / 1000);
        trackEventBeacon({ action: 'unload', duration });
    };
    window.addEventListener('pagehide', sendUnload);
    window.addEventListener('beforeunload', sendUnload);
}
/* =========================================================
   18. ПОДСКАЗКИ ДЛЯ ИКОНОК ПРЕИМУЩЕСТВ
   ========================================================= */
let activeTooltipElement = null;
let activeHideTooltipHandler = null;

window.showBadgeTooltip = function(el) {
    // Удаляем предыдущую подсказку и её обработчики, если они были
    if (activeTooltipElement) {
        activeTooltipElement.remove();
        if (activeHideTooltipHandler) {
            document.removeEventListener('touchstart', activeHideTooltipHandler);
            document.removeEventListener('mousedown', activeHideTooltipHandler);
        }
        activeTooltipElement = null;
        activeHideTooltipHandler = null;
    }

    const text = el.getAttribute('data-tooltip');
    if (!text) return;

    const tooltip = document.createElement('div');
    tooltip.className = 'mobile-badge-tooltip';
    tooltip.textContent = text;
    
    // Скрываем только через visibility, чтобы можно было замерить ширину.
    // НЕ используем opacity = '0', чтобы не сломать CSS-анимацию!
    tooltip.style.visibility = 'hidden';
    document.body.appendChild(tooltip);

    const rect = el.getBoundingClientRect();
    const iconCenterX = rect.left + rect.width / 2;
    
    tooltip.style.left = `${iconCenterX}px`;
    tooltip.style.top = `${rect.top}px`;
    
    const tooltipWidth = tooltip.offsetWidth;
    const margin = 15; 
    
    let shiftX = 0;
    const expectedLeft = iconCenterX - tooltipWidth / 2;
    const expectedRight = iconCenterX + tooltipWidth / 2;
    
    if (expectedLeft < margin) {
        shiftX = margin - expectedLeft;
    } else if (expectedRight > window.innerWidth - margin) {
        shiftX = window.innerWidth - margin - expectedRight;
    }

    tooltip.style.setProperty('--shift-x', `${shiftX}px`);
    
    let arrowLeft = (tooltipWidth / 2) - shiftX; 
    arrowLeft = Math.max(20, Math.min(tooltipWidth - 20, arrowLeft));
    
    tooltip.style.setProperty('--arrow-left', `${arrowLeft}px`);

    // Делаем видимой и запускаем анимацию
    tooltip.style.visibility = 'visible';
    void tooltip.offsetWidth; // Хак для принудительного обновления стилей
    tooltip.classList.add('show');
    
    activeTooltipElement = tooltip;

    const hideTooltip = (e) => {
        if (e && e.target.closest('.hero-badge-item')) return; 
        
        tooltip.classList.remove('show');
        setTimeout(() => {
            if (tooltip.parentNode) tooltip.remove();
        }, 200); 
        
        document.removeEventListener('touchstart', hideTooltip);
        document.removeEventListener('mousedown', hideTooltip);
        
        // Очищаем глобальные переменные только если это наша подсказка
        if (activeTooltipElement === tooltip) {
            activeTooltipElement = null;
            activeHideTooltipHandler = null;
        }
    };

    activeHideTooltipHandler = hideTooltip;

    setTimeout(() => {
        document.addEventListener('touchstart', hideTooltip, { passive: true });
        document.addEventListener('mousedown', hideTooltip);
    }, 50);
};
/* =========================================================
   19. АНИМАЦИЯ ИКОНОК (ОЧЕРЕДЬ)
   ========================================================= */
async function runBadgeSequence() {
    const badges = document.querySelectorAll('.hero-badge-item');
    if (badges.length === 0) return;

    const sleep = (ms) => new Promise(resolve => setTimeout(resolve, ms));

    while (true) {
        // 1. Очередное зажигание каждого значка
        for (let i = 0; i < badges.length; i++) {
            const el = badges[i];
            el.classList.add('is-bright'); 
            await sleep(1500);             // Ждем, пока нарисуется сам значок
            
            el.classList.add('orbiting');  
            await sleep(1200);             // Ждем 1.2 секунды, пока искра выжигает круг
        }

        // 2. Пауза перед "дыханием"
        await sleep(1000);

        // 3. Все одновременно дышат
        badges.forEach(el => el.classList.add('breathing'));
        await sleep(3000); // 5 раз по 0.6 сек

        // 4. Плавное затухание
        badges.forEach(el => {
            el.classList.remove('breathing');
            el.classList.add('fading');
        });
        await sleep(1000);

        // 5. Очистка перед новым циклом
        badges.forEach(el => {
            el.classList.remove('is-bright');
            el.classList.remove('fading');
            el.classList.remove('orbiting');
        });
        
        // 6. Пауза в 2 секунды и по новой
        await sleep(2000);
    }
}
/* =========================================================
   ИЗБРАННОЕ (сердечко в карточках товара)
   ========================================================= */
state.favorites = new Set(window.initialFavorites || []);

function isFavorite(productId) {
    return state.favorites.has(Number(productId));
}

function updateFavButtons(productId, favorited) {
    const pid = Number(productId);
    document.querySelectorAll(`.fav-btn[data-product-id="${pid}"]`).forEach(btn => {
        const icon = btn.querySelector('i');
        btn.classList.toggle('active', favorited);
        if (icon) {
            icon.classList.remove('fa-solid', 'fa-regular');
            icon.classList.add(favorited ? 'fa-solid' : 'fa-regular');
        }
        const label = favorited ? 'Убрать из избранного' : 'В избранное';
        btn.setAttribute('title', label);
        btn.setAttribute('aria-label', label);
        if (favorited) {
            btn.classList.remove('fav-pop');
            void btn.offsetWidth; // перезапуск анимации
            btn.classList.add('fav-pop');
        }
    });
}

async function toggleFavorite(productId, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    const pid = Number(productId);
    if (!pid) return;

    try {
        const res = await fetch('index.php?api=favorites', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ product_id: pid })
        });
        const data = await res.json().catch(() => ({}));

        if (data.success) {
            if (data.favorited) {
                state.favorites.add(pid);
            } else {
                state.favorites.delete(pid);
            }
            updateFavButtons(pid, !!data.favorited);
            showToast(data.message || (data.favorited ? 'Товар добавлен в избранное' : 'Товар удалён из избранного'));

            // На странице «Избранное» в кабинете — убираем карточку из списка
            if (!data.favorited && document.body.dataset.cabinet === 'favorites') {
                const card = document.querySelector(`.favorite-card[data-product-id="${pid}"]`);
                if (card) {
                    card.style.transition = 'opacity .3s ease, transform .3s ease';
                    card.style.opacity = '0';
                    card.style.transform = 'scale(.9)';
                    setTimeout(() => {
                        card.remove();
                        if (!document.querySelector('.favorite-card')) {
                            location.reload();
                        }
                    }, 300);
                }
            }
        } else if (data.need_auth) {
            showToast(data.message || 'Войдите, чтобы использовать избранное');
            const authModal = document.getElementById('auth-modal');
            if (authModal) {
                openModal('auth-modal');
            } else {
                window.location.href = 'index.php#catalog-section';
            }
        } else {
            showToast(data.message || 'Не удалось изменить избранное');
        }
    } catch (e) {
        console.error('Ошибка избранного:', e);
        showToast('Нет соединения с сервером');
    }
}

/* Синхронизация избранного при входе/выходе из аккаунта */
(function () {
    const origCheck = window.checkCurrentUser;
    if (typeof origCheck === 'function') {
        window.checkCurrentUser = async function () {
            await origCheck();
            try {
                const res = await fetch('index.php?api=favorites', { credentials: 'same-origin' });
                const data = await res.json();
                if (data.success && Array.isArray(data.favorites)) {
                    state.favorites = new Set(data.favorites.map(Number));
                    state.favorites.forEach(pid => updateFavButtons(pid, true));
                }
            } catch (e) { /* сервер недоступен — не критично */ }
        };
    }
})();
