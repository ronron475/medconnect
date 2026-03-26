<?php
// Landing page with login functionality
// This serves as the main entry point for the application
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>medConnect — AI-Powered Medical Video Consultation & Triage</title>
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>

<!-- CANVAS BUBBLES ONLY (background image is on .hero via CSS) -->
<div class="bg-canvas" aria-hidden="true">
  <canvas id="bubble-canvas"></canvas>
</div>

<!-- NAVBAR -->
<nav class="navbar" id="navbar">
  <div class="nav-container">
    <a href="#" class="nav-logo">
      <span class="logo-icon" aria-hidden="true">
        <svg width="30" height="30" viewBox="0 0 30 30" fill="none">
          <rect width="30" height="30" rx="9" fill="url(#lg1)"/>
          <path d="M15 8v14M8 15h14" stroke="#fff" stroke-width="2.8" stroke-linecap="round"/>
          <defs>
            <linearGradient id="lg1" x1="0" y1="0" x2="30" y2="30" gradientUnits="userSpaceOnUse">
              <stop stop-color="#0d9488"/>
              <stop offset="1" stop-color="#0ea5e9"/>
            </linearGradient>
          </defs>
        </svg>
      </span>
      <span class="logo-text">med<span class="logo-accent">Connect</span></span>
    </a>

    <button class="nav-toggle" id="nav-toggle" aria-label="Toggle navigation" aria-expanded="false">
      <span></span><span></span><span></span>
    </button>

    <div class="nav-menu" id="nav-menu">
      <ul class="nav-links">
        <li><a href="#how-it-works">How It Works</a></li>
        <li><a href="#specialties">Specialties</a></li>
        <li><a href="#providers">Healthcare Providers</a></li>
        <li><a href="#security">Security</a></li>
      </ul>
      <div class="nav-actions">
        <a href="#signin-card" class="btn-nav-signin">Sign In</a>
        <a href="#signin-card" class="btn-nav-book">Book Consultation</a>
      </div>
    </div>
  </div>
</nav>

<!-- HERO -->
<section class="hero">
  <div class="hero-container">

    <!-- LEFT -->
    <div class="hero-left">
      <div class="hero-eyebrow">
        <span class="eyebrow-dot" aria-hidden="true"></span>
        City Health Office of Bago City
      </div>

      <h1 class="hero-title">
        <span class="title-main">medConnect</span>
        <span class="title-sub">AI-Powered Medical<br/>Video Consultation<br/>&amp; Triage System</span>
      </h1>

      <p class="hero-desc">
        A secure, non-emergency healthcare platform connecting patients with licensed healthcare providers through AI-assisted triage, video consultation, and centralized medical records.
      </p>

      <div class="hero-ctas">
        <a href="#signin-card" class="cta-primary">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="4" rx="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
          Book Consultation
        </a>
        <a href="#how-it-works" class="cta-secondary">How It Works →</a>
      </div>

      <div class="hero-features">
        <div class="feature-pill">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
          AI-Assisted Triage
        </div>
        <div class="feature-pill">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 10l4.553-2.069A1 1 0 0 1 21 8.82v6.36a1 1 0 0 1-1.447.89L15 14"/><rect width="15" height="14" x="1" y="5" rx="2" ry="2"/></svg>
          Secure Video Consultation
        </div>
        <div class="feature-pill">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/></svg>
          EMR &amp; Follow-Up Monitoring
        </div>
      </div>
    </div>

    <!-- RIGHT: Consultation Card -->
    <div class="signin-card" id="signin-card">

      <div class="card-top">
        <div class="card-icon-wrap" aria-hidden="true">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
        </div>
        <div>
          <h2 class="card-title">Sign In</h2>
          <p class="card-sub">Non-emergency healthcare access — anytime, anywhere.</p>
        </div>
      </div>

      <div class="alert" id="alert" role="alert" aria-live="polite"></div>

      <form id="login-form" novalidate>

        <div class="form-group">
          <label for="email">Email Address</label>
          <div class="input-wrap">
            <span class="input-icon" aria-hidden="true">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
            </span>
            <input type="email" id="email" name="email" placeholder="you@example.com" autocomplete="email" required />
          </div>
          <span class="field-error" id="email-error" role="alert"></span>
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <div class="input-wrap">
            <span class="input-icon" aria-hidden="true">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </span>
            <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required />
            <button type="button" class="toggle-pwd" id="toggle-pwd" aria-label="Show password">
              <svg id="eye-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
            </button>
          </div>
          <span class="field-error" id="password-error" role="alert"></span>
        </div>

        <div class="form-footer-row">
          <a href="#" class="forgot-link" id="forgot-link">Forgot password?</a>
        </div>

        <button type="submit" class="btn-signin" id="submit-btn">
          <span id="btn-text">Sign In</span>
          <span id="btn-spinner" class="spinner" hidden aria-hidden="true"></span>
        </button>
      </form>

      <p class="card-register">
        New patient? <a href="../controllers/auth/register.controller.php">Create a patient account</a>
      </p>

      <p class="provider-note">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
        Provider access is managed by the system administrator.
      </p>

      <div class="emergency-note" role="note">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
        For emergency cases, please proceed to the nearest healthcare facility immediately.
      </p>

      <div class="card-badges">
        <span class="trust-badge">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          Data Privacy Compliant
        </span>
        <span class="trust-badge">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Secure Access
        </span>
        <span class="trust-badge">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          Verified Providers
        </span>
      </div>
    </div>

  </div>
</section>

<script src="../assets/js/script.js"></script>

<!-- ── Forgot Password Modal — 3-Step OTP Flow ── -->
<div id="forgot-modal" style="display:none;position:fixed;inset:0;z-index:999;background:rgba(0,0,0,0.55);backdrop-filter:blur(4px);align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:18px;padding:32px 36px;width:100%;max-width:440px;margin:20px;box-shadow:0 24px 80px rgba(0,0,0,0.25);position:relative">
    <button id="forgot-close" style="position:absolute;top:14px;right:16px;background:none;border:none;cursor:pointer;color:#94a3b8;font-size:22px;line-height:1">&times;</button>

    <!-- Step dots -->
    <div style="display:flex;align-items:center;margin-bottom:24px">
      <div style="display:flex;flex-direction:column;align-items:center;gap:4px;flex:1">
        <div id="fd1" style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#1a6db5,#3b82f6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700">1</div>
        <span id="fl1" style="font-size:10px;font-weight:600;color:#1a6db5">Email</span>
      </div>
      <div id="fln1" style="flex:1;height:2px;background:#e2e8f0;margin-bottom:14px"></div>
      <div style="display:flex;flex-direction:column;align-items:center;gap:4px;flex:1">
        <div id="fd2" style="width:28px;height:28px;border-radius:50%;background:#f1f5f9;color:#94a3b8;border:2px solid #e2e8f0;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700">2</div>
        <span id="fl2" style="font-size:10px;font-weight:600;color:#94a3b8">OTP</span>
      </div>
      <div id="fln2" style="flex:1;height:2px;background:#e2e8f0;margin-bottom:14px"></div>
      <div style="display:flex;flex-direction:column;align-items:center;gap:4px;flex:1">
        <div id="fd3" style="width:28px;height:28px;border-radius:50%;background:#f1f5f9;color:#94a3b8;border:2px solid #e2e8f0;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700">3</div>
        <span id="fl3" style="font-size:10px;font-weight:600;color:#94a3b8">New Password</span>
      </div>
    </div>

    <div id="fp-alert" style="display:none;padding:11px 14px;border-radius:9px;font-size:13px;margin-bottom:16px"></div>

    <!-- Step 1 -->
    <div id="fp-s1">
      <div style="font-size:16px;font-weight:800;color:#0f172a;margin-bottom:4px">Forgot Password?</div>
      <div style="font-size:13px;color:#64748b;margin-bottom:18px">Enter your email to receive a 6-digit OTP.</div>
      <label style="display:block;font-size:12.5px;font-weight:600;color:#0f172a;margin-bottom:6px">Email Address</label>
      <input type="email" id="fp-email" placeholder="your.email@example.com"
        style="width:100%;height:46px;padding:0 14px;border:1.5px solid #d0e4f7;border-radius:10px;font-size:14px;font-family:inherit;color:#0f172a;outline:none;box-sizing:border-box;margin-bottom:14px"/>
      <button id="fp-send" style="width:100%;height:48px;border:none;border-radius:11px;cursor:pointer;background:linear-gradient(135deg,#1a6db5,#3b82f6);color:#fff;font-size:14.5px;font-weight:700;font-family:inherit">
        <span id="fp-send-t">Send OTP</span>
        <span id="fp-send-s" hidden style="display:inline-block;width:14px;height:14px;border:2.5px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle"></span>
      </button>
    </div>

    <!-- Step 2 -->
    <div id="fp-s2" hidden>
      <div style="font-size:16px;font-weight:800;color:#0f172a;margin-bottom:4px">Enter OTP</div>
      <div id="fp-otp-note" style="font-size:13px;color:#64748b;margin-bottom:18px">OTP sent to your email.</div>
      <label style="display:block;font-size:12.5px;font-weight:600;color:#0f172a;margin-bottom:6px">6-Digit OTP</label>
      <input type="text" id="fp-otp" maxlength="6" inputmode="numeric" placeholder="000000"
        style="width:100%;height:54px;padding:0 14px;border:1.5px solid #d0e4f7;border-radius:10px;font-size:26px;font-weight:700;letter-spacing:8px;text-align:center;font-family:inherit;color:#0f172a;outline:none;box-sizing:border-box;margin-bottom:14px"/>
      <button id="fp-verify" style="width:100%;height:48px;border:none;border-radius:11px;cursor:pointer;background:linear-gradient(135deg,#1a6db5,#3b82f6);color:#fff;font-size:14.5px;font-weight:700;font-family:inherit">
        <span id="fp-verify-t">Verify OTP</span>
        <span id="fp-verify-s" hidden style="display:inline-block;width:14px;height:14px;border:2.5px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle"></span>
      </button>
      <p style="text-align:center;margin-top:12px;font-size:13px;color:#64748b">
        Didn't receive it? <button id="fp-resend" style="background:none;border:none;color:#1a6db5;cursor:pointer;font-size:13px;text-decoration:underline;padding:0">Resend</button>
        <span id="fp-cd" style="color:#94a3b8;font-size:13px"></span>
      </p>
    </div>

    <!-- Step 3 -->
    <div id="fp-s3" hidden>
      <div style="font-size:16px;font-weight:800;color:#0f172a;margin-bottom:4px">New Password</div>
      <div style="font-size:13px;color:#64748b;margin-bottom:18px">OTP verified. Set your new password.</div>
      <label style="display:block;font-size:12.5px;font-weight:600;color:#0f172a;margin-bottom:6px">New Password</label>
      <input type="password" id="fp-pw" placeholder="At least 6 characters"
        style="width:100%;height:46px;padding:0 14px;border:1.5px solid #d0e4f7;border-radius:10px;font-size:14px;font-family:inherit;color:#0f172a;outline:none;box-sizing:border-box;margin-bottom:12px"/>
      <label style="display:block;font-size:12.5px;font-weight:600;color:#0f172a;margin-bottom:6px">Confirm Password</label>
      <input type="password" id="fp-cpw" placeholder="Repeat your password"
        style="width:100%;height:46px;padding:0 14px;border:1.5px solid #d0e4f7;border-radius:10px;font-size:14px;font-family:inherit;color:#0f172a;outline:none;box-sizing:border-box;margin-bottom:14px"/>
      <button id="fp-reset" style="width:100%;height:48px;border:none;border-radius:11px;cursor:pointer;background:linear-gradient(135deg,#1a6db5,#3b82f6);color:#fff;font-size:14.5px;font-weight:700;font-family:inherit">
        <span id="fp-reset-t">Reset Password</span>
        <span id="fp-reset-s" hidden style="display:inline-block;width:14px;height:14px;border:2.5px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle"></span>
      </button>
    </div>

    <!-- Success -->
    <div id="fp-done" hidden style="text-align:center;padding:10px 0">
      <div style="width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#16a34a,#22c55e);display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <div style="font-size:17px;font-weight:800;color:#0f172a;margin-bottom:8px">Password Reset!</div>
      <div style="font-size:13px;color:#64748b;margin-bottom:20px">Your password has been updated. You can now sign in.</div>
      <button id="fp-signin" style="width:100%;height:48px;border:none;border-radius:11px;cursor:pointer;background:linear-gradient(135deg,#1a6db5,#3b82f6);color:#fff;font-size:14.5px;font-weight:700;font-family:inherit">Sign In Now</button>
    </div>
  </div>
</div>

<script>
(function(){
  const modal=document.getElementById('forgot-modal');
  const al=document.getElementById('fp-alert');
  let email='', timer=null;

  const A='background:linear-gradient(135deg,#1a6db5,#3b82f6);color:#fff;border:none;box-shadow:0 4px 14px rgba(26,109,181,.3);';
  const D='background:linear-gradient(135deg,#16a34a,#22c55e);color:#fff;border:none;';
  const I='background:#f1f5f9;color:#94a3b8;border:2px solid #e2e8f0;';
  const BASE='width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;';

  function sa(msg,t='e'){al.textContent=msg;al.style.display='block';al.style.background=t==='s'?'#f0fdf4':'#fef2f2';al.style.color=t==='s'?'#16a34a':'#dc2626';al.style.border=t==='s'?'1px solid #86efac':'1px solid #fca5a5';}
  function ca(){al.style.display='none';}
  function sl(btn,ts,ss,on){btn.disabled=on;ts.hidden=on;ss.hidden=!on;}

  function goStep(n){
    ['fp-s1','fp-s2','fp-s3','fp-done'].forEach((id,i)=>document.getElementById(id).hidden=(i+1!==n&&!(n===4&&i===3)));
    ca();
    const dots=[document.getElementById('fd1'),document.getElementById('fd2'),document.getElementById('fd3')];
    const lbls=[document.getElementById('fl1'),document.getElementById('fl2'),document.getElementById('fl3')];
    const lines=[document.getElementById('fln1'),document.getElementById('fln2')];
    const colors=['#1a6db5','#1a6db5','#1a6db5'];
    dots.forEach((d,i)=>{
      if(i+1<n){d.style.cssText=BASE+D;lbls[i].style.color='#16a34a';if(lines[i])lines[i].style.background='#86efac';}
      else if(i+1===n){d.style.cssText=BASE+A;lbls[i].style.color='#1a6db5';}
      else{d.style.cssText=BASE+I;lbls[i].style.color='#94a3b8';}
    });
  }

  function startCD(s){
    const btn=document.getElementById('fp-resend'),cd=document.getElementById('fp-cd');
    btn.disabled=true;let r=s;cd.textContent=` (${r}s)`;
    timer=setInterval(()=>{r--;if(r<=0){clearInterval(timer);btn.disabled=false;cd.textContent='';}else cd.textContent=` (${r}s)`;},1000);
  }

  async function sendOtp(e){
    const fd=new FormData();fd.append('email',e);
    return (await fetch('../request_password_reset.php',{method:'POST',body:fd})).json();
  }

  document.getElementById('forgot-link').addEventListener('click',ev=>{ev.preventDefault();modal.style.display='flex';goStep(1);document.getElementById('fp-email').focus();});
  document.getElementById('forgot-close').addEventListener('click',()=>modal.style.display='none');
  modal.addEventListener('click',e=>{if(e.target===modal)modal.style.display='none';});

  // Step 1
  document.getElementById('fp-send').addEventListener('click',async()=>{
    const e=document.getElementById('fp-email').value.trim();
    if(!e||!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e)){sa('Please enter a valid email address.');return;}
    const btn=document.getElementById('fp-send');
    sl(btn,document.getElementById('fp-send-t'),document.getElementById('fp-send-s'),true);
    try{
      const d=await sendOtp(e);
      if(d.success){email=e;document.getElementById('fp-otp-note').textContent=`OTP sent to ${e}`;document.getElementById('fp-otp').value='';goStep(2);startCD(60);document.getElementById('fp-otp').focus();}
      else sa(d.message);
    }catch{sa('Could not send OTP. Please try again.');}
    sl(btn,document.getElementById('fp-send-t'),document.getElementById('fp-send-s'),false);
  });

  document.getElementById('fp-resend').addEventListener('click',async()=>{
    clearInterval(timer);
    try{const d=await sendOtp(email);if(d.success){sa('New OTP sent.','s');startCD(60);document.getElementById('fp-otp').value='';}else sa(d.message);}
    catch{sa('Could not resend OTP.');}
  });

  // Step 2
  document.getElementById('fp-verify').addEventListener('click',async()=>{
    const otp=document.getElementById('fp-otp').value.trim();
    if(!otp||!/^\d{6}$/.test(otp)){sa('Please enter the 6-digit OTP.');return;}
    const btn=document.getElementById('fp-verify');
    sl(btn,document.getElementById('fp-verify-t'),document.getElementById('fp-verify-s'),true);
    try{
      const fd=new FormData();fd.append('email',email);fd.append('otp',otp);
      const d=await(await fetch('../controllers/auth/verify_reset_otp.php',{method:'POST',body:fd})).json();
      if(d.success){goStep(3);document.getElementById('fp-pw').focus();}else sa(d.message);
    }catch{sa('Could not verify OTP.');}
    sl(btn,document.getElementById('fp-verify-t'),document.getElementById('fp-verify-s'),false);
  });

  // Step 3
  document.getElementById('fp-reset').addEventListener('click',async()=>{
    const pw=document.getElementById('fp-pw').value,cpw=document.getElementById('fp-cpw').value;
    if(pw.length<6){sa('Password must be at least 6 characters.');return;}
    if(pw!==cpw){sa('Passwords do not match.');return;}
    const btn=document.getElementById('fp-reset');
    sl(btn,document.getElementById('fp-reset-t'),document.getElementById('fp-reset-s'),true);
    try{
      const fd=new FormData();fd.append('email',email);fd.append('password',pw);fd.append('confirm_password',cpw);
      const d=await(await fetch('../reset_password_otp.php',{method:'POST',body:fd})).json();
      if(d.success)goStep(4);else sa(d.message);
    }catch{sa('Could not reset password.');}
    sl(btn,document.getElementById('fp-reset-t'),document.getElementById('fp-reset-s'),false);
  });

  document.getElementById('fp-signin').addEventListener('click',()=>modal.style.display='none');
  document.getElementById('fp-email').addEventListener('keydown',e=>{if(e.key==='Enter')document.getElementById('fp-send').click();});
  document.getElementById('fp-otp').addEventListener('keydown',e=>{if(e.key==='Enter')document.getElementById('fp-verify').click();});
})();
</script>
</body>
</html>
