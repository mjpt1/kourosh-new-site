// لایه گیمینگ: ستاره‌ها، کج شدن کارت‌ها، اسلایدر پرفروش‌ها، نوار موبایل
(() => {
  const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;

  // آسمان پرستاره متحرک
  const cv = document.getElementById('fx-stars');
  if (cv && !calm) {
    const ctx = cv.getContext('2d'); let W, H, stars = [];
    const size = () => { W = cv.width = innerWidth; H = cv.height = innerHeight; stars = Array.from({ length: Math.min(140, W / 8 | 0) }, () => ({ x: Math.random() * W, y: Math.random() * H, r: Math.random() * 1.4 + .2, s: Math.random() * .3 + .05, t: Math.random() * 6 })); };
    size(); addEventListener('resize', size);
    const draw = () => {
      if (!document.hidden) {
        ctx.clearRect(0, 0, W, H);
        for (const s of stars) {
          s.y += s.s; s.t += .03; if (s.y > H) { s.y = 0; s.x = Math.random() * W; }
          ctx.globalAlpha = .4 + Math.sin(s.t) * .35;
          ctx.fillStyle = s.r > 1.2 ? '#b9a8ff' : '#dfe6ff';
          ctx.beginPath(); ctx.arc(s.x, s.y, s.r, 0, 7); ctx.fill();
        }
      }
      requestAnimationFrame(draw);
    };
    draw();
  }

  // کج شدن سه‌بعدی کارت‌ها روی دسکتاپ
  if (!calm && matchMedia('(hover:hover) and (min-width:901px)').matches) {
    document.querySelectorAll('.product,.hcard').forEach(c => {
      c.addEventListener('mousemove', e => { const r = c.getBoundingClientRect(); const x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5; c.style.transform = `perspective(700px) rotateY(${x * 10}deg) rotateX(${-y * 10}deg) translateY(-6px)`; });
      c.addEventListener('mouseleave', () => { c.style.transform = ''; });
    });
  }

  // اسلایدر خودکار پرفروش‌ها با دکمه‌های مثلثی
  const rail = document.querySelector('.hot-rail');
  if (rail) {
    const box = rail.parentElement;
    const dir = getComputedStyle(rail).direction === 'rtl' ? -1 : 1;
    const step = () => { const c = rail.children[0]; return c ? c.getBoundingClientRect().width + 16 : 260; };
    const atEnd = () => Math.abs(rail.scrollLeft) + rail.clientWidth >= rail.scrollWidth - 6;
    const atStart = () => Math.abs(rail.scrollLeft) < 6;
    const go = n => {
      if (n > 0 && atEnd()) rail.scrollTo({ left: 0, behavior: 'smooth' });
      else if (n < 0 && atStart()) rail.scrollTo({ left: dir * rail.scrollWidth, behavior: 'smooth' });
      else rail.scrollBy({ left: dir * n * step(), behavior: 'smooth' });
    };
    let timer = null, paused = false;
    const start = () => { if (calm || timer) return; timer = setInterval(() => { if (!paused && !document.hidden) go(1); }, 3000); };
    const stop = () => { clearInterval(timer); timer = null; };
    box.querySelector('.tri-next').onclick = () => { go(1); stop(); start(); };
    box.querySelector('.tri-prev').onclick = () => { go(-1); stop(); start(); };
    box.addEventListener('mouseenter', () => paused = true);
    box.addEventListener('mouseleave', () => paused = false);
    rail.addEventListener('touchstart', () => paused = true, { passive: true });
    rail.addEventListener('touchend', () => setTimeout(() => paused = false, 2500), { passive: true });
    new IntersectionObserver(es => es[0].isIntersecting ? start() : stop(), { threshold: .2 }).observe(rail);
  }

  // انتخاب ریجن روی کارت: قیمت، تخفیف، موجودی و دکمه افزودن عوض می‌شود
  const nf = n => new Intl.NumberFormat('fa-IR').format(n) + ' تومان';
  const pick = (card, b) => {
    card.querySelectorAll('.rg').forEach(x => { x.classList.toggle('on', x === b); x.setAttribute('aria-checked', x === b); });
    const price = +b.dataset.price, old = +b.dataset.old, stock = +b.dataset.stock;
    card.querySelector('.price-row strong').textContent = nf(price);
    const del = card.querySelector('.price-row del'); del.hidden = !(old > price); del.textContent = nf(old);
    const disc = card.querySelector('.discount'); disc.hidden = !(old > price); if (old > price) disc.textContent = Math.round((1 - price / old) * 100) + '٪ تخفیف';
    const st = card.querySelector('.stock'); st.classList.toggle('out', !stock); st.textContent = '● ' + (stock ? 'موجود' : 'ناموجود');
    card.querySelectorAll('[data-add]').forEach(a => { a.dataset.add = stock ? b.dataset.key : ''; a.disabled = !stock; });
    card.dataset.price = price;
  };
  document.querySelectorAll('.product .rg').forEach(b => b.addEventListener('click', () => pick(b.closest('.product'), b)));
  // وقتی مشتری ریجن را از فیلتر بالا انتخاب کند، همان ریجن روی همه کارت‌ها انتخاب می‌شود
  const rf = document.getElementById('region-filter');
  if (rf) rf.addEventListener('change', () => { if (rf.value === 'all') return; document.querySelectorAll('.product').forEach(c => { const b = c.querySelector(`.rg[data-r="${rf.value}"]`); if (b) pick(c, b); }); });
  // کلیک روی کارت پرفروش‌ها: رفتن به محصول و درخشش آن
  document.querySelectorAll('[data-jump]').forEach(a => a.addEventListener('click', e => {
    const c = document.getElementById('p' + a.dataset.jump); if (!c) return; e.preventDefault();
    if (c.hidden) { document.querySelector('[data-filter="all"]')?.click(); }
    c.scrollIntoView({ behavior: 'smooth', block: 'center' }); c.classList.remove('flash'); void c.offsetWidth; c.classList.add('flash');
  }));

  // دکمه سبد در نوار موبایل
  const tb = document.querySelector('[data-tab-cart]');
  if (tb) tb.onclick = () => document.querySelector('.cart-trigger')?.click();
  const count = document.querySelector('[data-count]'), badge = document.querySelector('[data-tab-count]');
  if (count && badge) new MutationObserver(() => { badge.textContent = count.textContent; badge.hidden = count.textContent === '0'; }).observe(count, { childList: true, characterData: true, subtree: true });
})();
