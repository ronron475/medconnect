/* ===== HOME LINK — scroll to top if already on homepage ===== */
const isLandingPage = document.body.classList.contains('landing-page');
const navHome = document.getElementById('nav-home');
if (navHome && !isLandingPage) {
  navHome.addEventListener('click', e => {
    if (window.location.pathname === '/' || window.location.pathname.endsWith('index.php')) {
      e.preventDefault();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  });
}

/* ===== SMOOTH SCROLL — anchor links with navbar offset ===== */
if (!isLandingPage) {
  document.querySelectorAll('a[href^="#"]').forEach(link => {
    link.addEventListener('click', e => {
      const id = link.getAttribute('href').slice(1);
      if (!id) return;
      const target = document.getElementById(id);
      if (target) {
        e.preventDefault();
        const navbarHeight = document.getElementById('navbar')?.offsetHeight || 70;
        const top = target.getBoundingClientRect().top + window.scrollY - navbarHeight - 16;
        window.scrollTo({ top, behavior: 'smooth' });
        // close mobile nav if open
        const nm = document.getElementById('nav-menu');
        const nt = document.getElementById('nav-toggle');
        if (nm) nm.classList.remove('open');
        if (nt) nt.setAttribute('aria-expanded', 'false');
      }
    });
  });
}

/* ===== SIGN-IN MODAL ===== */
(function () {
  const overlay = document.getElementById('signin-modal');
  let savedScroll = 0;
  let closeTimer  = null;

  function openModal() {
    if (!overlay) return;
    // clear any in-progress close
    clearTimeout(closeTimer);
    overlay.classList.remove('is-closing');

    savedScroll = window.scrollY;
    overlay.removeAttribute('hidden');

    // double rAF so display:flex is painted before transition starts
    requestAnimationFrame(() => requestAnimationFrame(() => {
      overlay.classList.add('is-open');
    }));

    // lock scroll without page jump
    document.body.style.top      = `-${savedScroll}px`;
    document.body.style.position = 'fixed';
    document.body.style.width    = '100%';
    document.body.style.overflow = 'hidden';
    document.body.classList.add('signin-active');
    document.dispatchEvent(new CustomEvent('medconnect:signin', { detail: { open: true } }));

    const first = overlay.querySelector('input');
    if (first) setTimeout(() => first.focus(), 320);
  }

  function closeModal() {
    if (!overlay) return;
    // start exit animation
    overlay.classList.remove('is-open');
    overlay.classList.add('is-closing');

    // restore scroll immediately (body unfix before overlay hides)
    document.body.style.position = '';
    document.body.style.top      = '';
    document.body.style.width    = '';
    document.body.style.overflow = '';
    document.body.classList.remove('signin-active');
    document.dispatchEvent(new CustomEvent('medconnect:signin', { detail: { open: false } }));
    window.scrollTo({ top: savedScroll, behavior: 'instant' });

    // hide after longest exit transition (0.26s overlay fade)
    closeTimer = setTimeout(() => {
      overlay.classList.remove('is-closing');
      overlay.setAttribute('hidden', '');
    }, 300);
  }

  ['open-signin-modal', 'open-book-cta'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', () => {
      const nm = document.getElementById('nav-menu');
      const nt = document.getElementById('nav-toggle');
      if (nm) nm.classList.remove('open');
      if (nt) nt.setAttribute('aria-expanded', false);
      openModal();
    });
  });

  const closeBtn = document.getElementById('close-signin-modal');
  if (closeBtn) closeBtn.addEventListener('click', closeModal);

  if (overlay) {
    overlay.addEventListener('click', e => {
      if (e.target === overlay) closeModal();
    });
  }

  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape' || !overlay || overlay.hasAttribute('hidden')) return;
    if (document.body.classList.contains('fab-modal-open')) return;
    if (document.body.classList.contains('signin-req-drawer-open')) return;
    const fab = document.getElementById('landing-fab');
    if (fab && fab.dataset.open === 'true') return;
    closeModal();
  });

  window.closeSignInModal = closeModal;
  window.openSignInModal = openModal;

  const authQs = new URLSearchParams(window.location.search);
  if (authQs.has('registered') || authQs.has('session_expired') || authQs.has('setup_complete')) {
    openModal();
  }
})();

/* ===== NAVBAR SCROLL ===== */
const navbar = document.getElementById('navbar');
if (navbar) {
  window.addEventListener('scroll', () => {
    navbar.classList.toggle('scrolled', window.scrollY > 20);
  }, { passive: true });
}

/* ===== MOBILE NAV (non-landing pages — landing uses landing-interactions.js) ===== */
if (!isLandingPage) {
  const navToggle = document.getElementById('nav-toggle');
  const navMenu   = document.getElementById('nav-menu');
  if (navToggle && navMenu) {
    navToggle.addEventListener('click', () => {
      const open = navMenu.classList.toggle('open');
      navToggle.setAttribute('aria-expanded', open);
      const spans = navToggle.querySelectorAll('span');
      if (open) {
        spans[0].style.transform = 'translateY(7px) rotate(45deg)';
        spans[1].style.opacity   = '0';
        spans[2].style.transform = 'translateY(-7px) rotate(-45deg)';
      } else {
        spans[0].style.transform = '';
        spans[1].style.opacity   = '';
        spans[2].style.transform = '';
      }
    });
    navMenu.querySelectorAll('a, button').forEach(el => {
      el.addEventListener('click', () => {
        navMenu.classList.remove('open');
        navToggle.setAttribute('aria-expanded', 'false');
        const spans = navToggle.querySelectorAll('span');
        spans[0].style.transform = '';
        spans[1].style.opacity   = '';
        spans[2].style.transform = '';
      });
    });
  }
}

/* ===== BUBBLE CANVAS ANIMATION ===== */
(function () {
  const canvas = document.getElementById('bubble-canvas');
  if (!canvas) return;
  const ctx    = canvas.getContext('2d');
  let W, H, bubbles = [];

  function resize() {
    W = canvas.width  = window.innerWidth;
    H = canvas.height = window.innerHeight;
  }
  window.addEventListener('resize', resize, { passive: true });
  resize();

  const COLORS = [
    'rgba(6,147,150,',  /* Brand Teal */
    'rgba(1,42,74,',    /* Navy */
    'rgba(96,131,149,', /* Slate Muted */
  ];

  function makeBubble() {
    const r = Math.random() * 80 + 40;
    return {
      x:    Math.random() * W,
      y:    H + r + Math.random() * 500,
      r,
      color: COLORS[Math.floor(Math.random() * COLORS.length)],
      alpha: Math.random() * 0.04 + 0.01, /* Even more subtle */
      speed: Math.random() * 0.2 + 0.1,
      drift: (Math.random() - 0.5) * 0.1,
      wobble: Math.random() * Math.PI * 2,
      wobbleSpeed: Math.random() * 0.005 + 0.002,
    };
  }

  for (let i = 0; i < 15; i++) {
    const b = makeBubble();
    b.y = Math.random() * H;
    bubbles.push(b);
  }

  function draw() {
    ctx.clearRect(0, 0, W, H);
    bubbles.forEach(b => {
      b.y      -= b.speed;
      b.wobble += b.wobbleSpeed;
      b.x      += Math.sin(b.wobble) * b.drift;

      const grad = ctx.createRadialGradient(b.x, b.y, 0, b.x, b.y, b.r);
      grad.addColorStop(0,   b.color + (b.alpha * 1.5) + ')');
      grad.addColorStop(0.5, b.color + b.alpha + ')');
      grad.addColorStop(1,   b.color + '0)');
      ctx.beginPath();
      ctx.arc(b.x, b.y, b.r, 0, Math.PI * 2);
      ctx.fillStyle = grad;
      ctx.fill();

      if (b.y + b.r < -50) {
        Object.assign(b, makeBubble());
      }
    });
    requestAnimationFrame(draw);
  }
  draw();
})();

/* ===== HERO CANVAS — medical-tech network animation ===== */
(function () {
  const canvas = document.getElementById('hero-canvas');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  let W, H, nodes = [], t = 0;

  const NODE_COUNT   = 45;
  const LINK_DIST    = 180;
  const NODE_SPEED   = 0.35;
  const PULSE_PERIOD = 200;

  function resize() {
    W = canvas.width  = canvas.offsetWidth  || window.innerWidth;
    H = canvas.height = canvas.offsetHeight || window.innerHeight;
  }

  const hero = canvas.parentElement;
  if (window.ResizeObserver) {
    new ResizeObserver(resize).observe(hero);
  } else {
    window.addEventListener('resize', resize, { passive: true });
  }
  resize();

  const TEAL  = [6, 147, 150];
  const NAVY  = [1, 42, 74];

  function makeNode() {
    return {
      x:  Math.random() * W,
      y:  Math.random() * H,
      vx: (Math.random() - 0.5) * NODE_SPEED,
      vy: (Math.random() - 0.5) * NODE_SPEED,
      r:  Math.random() * 2 + 1,
      c:  Math.random() > 0.5 ? TEAL : NAVY,
      pulse: 0,
      pulseNext: Math.floor(Math.random() * PULSE_PERIOD),
      pulseR: 0,
      pulseAlpha: 0,
    };
  }

  for (let i = 0; i < NODE_COUNT; i++) nodes.push(makeNode());

  function draw() {
    ctx.clearRect(0, 0, W, H);
    t++;

    nodes.forEach(n => {
      n.x += n.vx;
      n.y += n.vy;
      if (n.x < 0 || n.x > W) n.vx *= -1;
      if (n.y < 0 || n.y > H) n.vy *= -1;

      if (t >= n.pulseNext) {
        n.pulse      = 1;
        n.pulseR     = n.r;
        n.pulseAlpha = 0.3;
        n.pulseNext  = t + PULSE_PERIOD + Math.floor(Math.random() * PULSE_PERIOD);
      }
      if (n.pulse) {
        n.pulseR    += 1.2;
        n.pulseAlpha -= 0.01;
        if (n.pulseAlpha <= 0) n.pulse = 0;
      }
    });

    for (let i = 0; i < nodes.length; i++) {
      for (let j = i + 1; j < nodes.length; j++) {
        const a = nodes[i], b = nodes[j];
        const dx = a.x - b.x, dy = a.y - b.y;
        const dist = Math.sqrt(dx * dx + dy * dy);
        if (dist > LINK_DIST) continue;

        const alpha = (1 - dist / LINK_DIST) * 0.12;
        ctx.beginPath();
        ctx.moveTo(a.x, a.y);
        ctx.lineTo(b.x, b.y);
        ctx.strokeStyle = `rgba(${a.c[0]},${a.c[1]},${a.c[2]},${alpha})`;
        ctx.lineWidth   = 0.6;
        ctx.stroke();
      }
    }

    nodes.forEach(n => {
      if (n.pulse) {
        ctx.beginPath();
        ctx.arc(n.x, n.y, n.pulseR, 0, Math.PI * 2);
        ctx.strokeStyle = `rgba(${n.c[0]},${n.c[1]},${n.c[2]},${n.pulseAlpha})`;
        ctx.lineWidth   = 1;
        ctx.stroke();
      }
      ctx.beginPath();
      ctx.arc(n.x, n.y, n.r, 0, Math.PI * 2);
      ctx.fillStyle = `rgba(${n.c[0]},${n.c[1]},${n.c[2]},0.4)`;
      ctx.fill();
    });

    requestAnimationFrame(draw);
  }
  draw();
})();

/* ===== SCROLL REVEAL ===== */
if (!isLandingPage) {
(function() {
  const reveals = document.querySelectorAll('.service-card, .contact-col, .hero-left, .hero-right');
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('mc-visible');
      }
    });
  }, { threshold: 0.1 });

  reveals.forEach(el => {
    el.classList.add('mc-reveal');
    observer.observe(el);
  });
})();
}

/* ===== ROLE TABS ===== */
// Removed â€” sign-in card is patient-only

/* ===== PASSWORD TOGGLE ===== */
const toggleBtn = document.getElementById('toggle-pwd');
const pwdInput  = document.getElementById('password');
const eyeIcon   = document.getElementById('eye-icon');
const EYE_OPEN   = `<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>`;
const EYE_CLOSED = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-10-7-10-7a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/><line x1="2" y1="2" x2="22" y2="22"/>`;
if (toggleBtn && pwdInput && eyeIcon) {
  toggleBtn.addEventListener('click', () => {
    const show = pwdInput.type === 'password';
    pwdInput.type    = show ? 'text' : 'password';
    eyeIcon.innerHTML = show ? EYE_CLOSED : EYE_OPEN;
    toggleBtn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  });
}

/* ===== FORM VALIDATION ===== */
const form          = document.getElementById('login-form');
const emailInput    = document.getElementById('email');
const emailError    = document.getElementById('email-error');
const passwordError = document.getElementById('password-error');
const alertBox      = document.getElementById('alert');
const submitBtn     = document.getElementById('submit-btn');
const btnText       = document.getElementById('btn-text');
const btnSpinner    = document.getElementById('btn-spinner');

if (form && emailInput && pwdInput && submitBtn && btnText && btnSpinner && alertBox && emailError && passwordError) {
const validateEmail = v => {
  if (!v) return 'Email address is required.';
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'Please enter a valid email address.';
  return '';
};
const validatePassword = v => {
  if (!v) return 'Password is required.';
  if (v.length < 6) return 'Password must be at least 6 characters.';
  return '';
};
const showAlert  = (msg, type = 'error') => { alertBox.textContent = msg; alertBox.className = `alert ${type}`; };
const clearAlert = () => { alertBox.className = 'alert'; alertBox.textContent = ''; };
const setLoading = on => { submitBtn.disabled = on; btnText.hidden = on; btnSpinner.hidden = !on; };

emailInput.addEventListener('blur', () => {
  const e = validateEmail(emailInput.value.trim());
  emailError.textContent = e;
  emailInput.classList.toggle('invalid', !!e);
});
pwdInput.addEventListener('blur', () => {
  const e = validatePassword(pwdInput.value);
  passwordError.textContent = e;
  pwdInput.classList.toggle('invalid', !!e);
});

form.addEventListener('submit', async e => {
  e.preventDefault();
  clearAlert();
  const eErr = validateEmail(emailInput.value.trim());
  const pErr = validatePassword(pwdInput.value);
  emailError.textContent    = eErr;
  passwordError.textContent = pErr;
  emailInput.classList.toggle('invalid', !!eErr);
  pwdInput.classList.toggle('invalid', !!pErr);
  if (eErr || pErr) return;

  setLoading(true);

  const body = new FormData();
  body.append('email',    emailInput.value.trim());
  body.append('password', pwdInput.value);

  try {
    // APP_BASE is set by index.php — works on localhost subfolder and domain root
    const base     = (typeof window.APP_BASE !== 'undefined') ? window.APP_BASE : '';
    const loginUrl = base + '/app/api/login.php';

    const res  = await fetch(loginUrl, { method: 'POST', body });

    if (!res.ok) {
      let msg = `Server error (${res.status}).`;
      try { const d = await res.json(); if (d.message) msg = d.message; } catch(_) {}
      showAlert(msg); setLoading(false); return;
    }

    let data;
    try { data = await res.json(); }
    catch(_) {
      const raw = await res.clone().text().catch(() => '(unreadable)');
      console.error('LOGIN RAW RESPONSE:', raw);
      showAlert('Server error: ' + raw.substring(0, 200));
      setLoading(false); return;
    }

    if (data.success) {
      if (window.MedConnectLoginLoading && typeof window.MedConnectLoginLoading.show === 'function') {
        window.MedConnectLoginLoading.show(data.redirect);
      } else {
        window.location.replace(data.redirect);
      }
    } else {
      showAlert(data.message || 'Invalid credentials. Please try again.');
      setLoading(false);
    }
  } catch (err) {
    console.error('Login fetch failed â€” error name:', err.name);
    console.error('Login fetch failed â€” error message:', err.message);
    console.error('Login fetch failed â€” full error:', err);
    const msg = err.name === 'TypeError'
      ? 'Connection blocked. Check browser console for details.'
      : (!navigator.onLine ? 'You appear to be offline.' : 'Could not reach the server.');
    showAlert(msg);
    setLoading(false);
  }
});
}

/* ===== TYPEWRITER ===== */
(function () {
  const mainEl = document.getElementById('tw-main');
  const subEl  = document.getElementById('tw-sub');
  if (!mainEl) return;

  // Full text to type
  const fullMain = mainEl.textContent || 'medConnect';
  const fullSub  = subEl ? (subEl.innerText || '') : '';
  const subLines = fullSub.split('\n').map(s => s.trim()).filter(Boolean);

  // Clear
  mainEl.innerHTML = '';
  if (subEl) { subEl.innerHTML = ''; subEl.style.visibility = 'hidden'; }

  let phase = 1, i = 0, li = 0, ci = 0;

  function tick() {
    if (phase === 1) {
      if (i <= fullMain.length) {
        const med = fullMain.slice(0, Math.min(i, 3));
        const con = i > 3 ? fullMain.slice(3, i) : '';
        mainEl.innerHTML = `<span style="color:#fff">${med}</span><span style="color:#2dd4bf">${con}</span><span class="tw-cursor">|</span>`;
        i++;
        setTimeout(tick, 100);
      } else {
        mainEl.innerHTML = `<span style="color:#fff">med</span><span style="color:#2dd4bf">Connect</span>`;
        if (subEl) subEl.style.visibility = 'visible';
        phase = 2;
        setTimeout(tick, 200);
      }
    } else {
      if (!subEl || !subLines.length) return;
      if (li < subLines.length) {
        if (ci <= subLines[li].length) {
          let html = '';
          for (let x = 0; x < li; x++) html += `<span style="display:block">${subLines[x]}</span>`;
          html += `<span style="display:block">${subLines[li].slice(0, ci)}<span class="tw-cursor">|</span></span>`;
          subEl.innerHTML = html;
          ci++;
          setTimeout(tick, 40);
        } else { li++; ci = 0; setTimeout(tick, 100); }
      } else {
        subEl.innerHTML = subLines.map(l => `<span style="display:block">${l}</span>`).join('');
      }
    }
  }

  setTimeout(tick, 300);
})();

/* ===== SCROLL REVEAL ===== */
if (!isLandingPage) {
(function () {
  // Tag elements that should reveal on scroll
  const selectors = [
    '.services-header',
    '.service-card',
    '.contact-col',
    '.contact-bottom',
  ];

  selectors.forEach(sel => {
    document.querySelectorAll(sel).forEach((el, i) => {
      el.classList.add('mc-reveal');
      // stagger siblings within the same parent
      const delay = ['mc-reveal-d1','mc-reveal-d2','mc-reveal-d3','mc-reveal-d4'];
      if (delay[i % 4]) el.classList.add(delay[i % 4]);
    });
  });

  const io = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('mc-visible');
        io.unobserve(entry.target);
      }
    });
  }, { threshold: 0.12 });

  document.querySelectorAll('.mc-reveal').forEach(el => io.observe(el));
})();
}

/* ===== ECG / HEARTBEAT BACKGROUND ===== */
(function () {
  const canvas = document.getElementById('ecg-canvas');
  if (!canvas) return;
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  const ctx = canvas.getContext('2d');
  let W, H, offset = 0;

  // Softer ECG cycle — reduced R spike, gentler P and T waves
  const CYCLE = [
    [0.00,  0.00],
    [0.10,  0.00],
    [0.14,  0.06],  // P wave — very subtle
    [0.18,  0.00],
    [0.24,  0.00],
    [0.27, -0.08],  // Q dip — minimal
    [0.30,  0.55],  // R spike — reduced from 1.0 to 0.55
    [0.33, -0.10],  // S dip — softened
    [0.37,  0.00],
    [0.46,  0.00],
    [0.52,  0.14],  // T wave — gentle
    [0.58,  0.14],
    [0.64,  0.00],
    [1.00,  0.00],
  ];

  function resize() {
    W = canvas.width  = canvas.offsetWidth  || window.innerWidth;
    H = canvas.height = canvas.offsetHeight || window.innerHeight;
  }

  const hero = canvas.parentElement;
  if (window.ResizeObserver) {
    new ResizeObserver(resize).observe(hero);
  } else {
    window.addEventListener('resize', resize, { passive: true });
  }
  resize();

  const REPEATS   = 4;                                    // more cycles = smaller peaks
  const Y_CENTER  = 0.86;                                 // bottom 14% — well below content
  const AMPLITUDE = () => Math.min(H * 0.055, 36);       // much smaller amplitude
  const SPEED     = 0.35;                                 // slower scroll

  function buildPath(cycleW, yBase, amp, xStart) {
    ctx.beginPath();
    let first = true;
    for (let r = -1; r <= REPEATS + 1; r++) {
      for (let i = 0; i < CYCLE.length; i++) {
        const [nx, ny] = CYCLE[i];
        const x = xStart + (r + nx) * cycleW;
        const y = yBase  - ny * amp;
        if (first) { ctx.moveTo(x, y); first = false; }
        else        { ctx.lineTo(x, y); }
      }
    }
  }

  function draw() {
    ctx.clearRect(0, 0, W, H);

    const cycleW = W / REPEATS;
    const yBase  = H * Y_CENTER;
    const amp    = AMPLITUDE();

    offset = (offset + SPEED) % cycleW;
    const xStart = -offset;

    ctx.lineJoin = 'round';
    ctx.lineCap  = 'round';

    // Single soft glow pass — no harsh double-line
    buildPath(cycleW, yBase, amp, xStart);
    ctx.strokeStyle = 'rgba(45,212,191,0.65)';
    ctx.lineWidth   = 1.8;
    ctx.shadowColor = 'rgba(45,212,191,0.30)';
    ctx.shadowBlur  = 8;
    ctx.stroke();

    ctx.shadowBlur = 0;
    requestAnimationFrame(draw);
  }

  draw();
})();
