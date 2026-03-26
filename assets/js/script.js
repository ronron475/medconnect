/* ===== NAVBAR SCROLL ===== */
const navbar = document.getElementById('navbar');
window.addEventListener('scroll', () => {
  navbar.classList.toggle('scrolled', window.scrollY > 20);
}, { passive: true });

/* ===== MOBILE NAV ===== */
const navToggle = document.getElementById('nav-toggle');
const navMenu   = document.getElementById('nav-menu');
navToggle.addEventListener('click', () => {
  const open = navMenu.classList.toggle('open');
  navToggle.setAttribute('aria-expanded', open);
});
navMenu.querySelectorAll('a').forEach(a => {
  a.addEventListener('click', () => {
    navMenu.classList.remove('open');
    navToggle.setAttribute('aria-expanded', false);
  });
});

/* ===== BUBBLE CANVAS ANIMATION ===== */
(function () {
  const canvas = document.getElementById('bubble-canvas');
  const ctx    = canvas.getContext('2d');
  let W, H, bubbles = [];

  function resize() {
    W = canvas.width  = window.innerWidth;
    H = canvas.height = window.innerHeight;
  }
  window.addEventListener('resize', resize, { passive: true });
  resize();

  const COLORS = [
    'rgba(13,148,136,',
    'rgba(45,212,191,',
    'rgba(14,165,233,',
    'rgba(34,211,238,',
  ];

  function makeBubble() {
    const r = Math.random() * 60 + 20;
    return {
      x:    Math.random() * W,
      y:    H + r + Math.random() * 200,
      r,
      color: COLORS[Math.floor(Math.random() * COLORS.length)],
      alpha: Math.random() * 0.07 + 0.02,
      speed: Math.random() * 0.25 + 0.08,
      drift: (Math.random() - 0.5) * 0.18,
      wobble: Math.random() * Math.PI * 2,
      wobbleSpeed: Math.random() * 0.008 + 0.003,
    };
  }

  for (let i = 0; i < 22; i++) {
    const b = makeBubble();
    b.y = Math.random() * H; // spread on load
    bubbles.push(b);
  }

  function draw() {
    ctx.clearRect(0, 0, W, H);
    bubbles.forEach(b => {
      b.y      -= b.speed;
      b.wobble += b.wobbleSpeed;
      b.x      += Math.sin(b.wobble) * b.drift;

      // soft glow
      const grad = ctx.createRadialGradient(b.x, b.y, 0, b.x, b.y, b.r);
      grad.addColorStop(0,   b.color + (b.alpha * 1.4) + ')');
      grad.addColorStop(0.5, b.color + b.alpha + ')');
      grad.addColorStop(1,   b.color + '0)');
      ctx.beginPath();
      ctx.arc(b.x, b.y, b.r, 0, Math.PI * 2);
      ctx.fillStyle = grad;
      ctx.fill();

      if (b.y + b.r < -20) {
        Object.assign(b, makeBubble());
      }
    });
    requestAnimationFrame(draw);
  }
  draw();
})();

/* ===== ROLE TABS ===== */
// Removed — sign-in card is patient-only

/* ===== PASSWORD TOGGLE ===== */
const toggleBtn = document.getElementById('toggle-pwd');
const pwdInput  = document.getElementById('password');
const eyeIcon   = document.getElementById('eye-icon');
const EYE_OPEN   = `<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>`;
const EYE_CLOSED = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-10-7-10-7a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/><line x1="2" y1="2" x2="22" y2="22"/>`;
toggleBtn.addEventListener('click', () => {
  const show = pwdInput.type === 'password';
  pwdInput.type    = show ? 'text' : 'password';
  eyeIcon.innerHTML = show ? EYE_CLOSED : EYE_OPEN;
  toggleBtn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
});

/* ===== FORM VALIDATION ===== */
const form          = document.getElementById('login-form');
const emailInput    = document.getElementById('email');
const emailError    = document.getElementById('email-error');
const passwordError = document.getElementById('password-error');
const alertBox      = document.getElementById('alert');
const submitBtn     = document.getElementById('submit-btn');
const btnText       = document.getElementById('btn-text');
const btnSpinner    = document.getElementById('btn-spinner');

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
    // login endpoint moved to /public/login.php
    // Works whether the UI is served from /public or /controllers/auth/.
    const appRoot  = window.location.pathname.replace(/\/(public|controllers\/auth|controllers\/patient)\/[^/]*$/, '');
    const loginUrl = appRoot + '/public/login.php';
    const res       = await fetch(loginUrl, { method: 'POST', body });
    const data = await res.json();
    if (data.success) {
      showAlert('Signed in successfully. Redirecting…', 'success');
      setTimeout(() => { window.location.href = data.redirect; }, 1000);
    } else {
      showAlert(data.message || 'Invalid credentials. Please try again.');
      setLoading(false);
    }
  } catch {
    showAlert('A network error occurred. Please try again.');
    setLoading(false);
  }
});
