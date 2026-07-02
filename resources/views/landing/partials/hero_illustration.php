<?php /** Hero healthcare isometric illustration — landing home.php */ ?>
<div class="hero-illustration-wrap" aria-hidden="true">
  <svg class="hero-illus" viewBox="0 0 580 470" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Healthcare telemedicine illustration">
    <defs>
      <linearGradient id="mc-iso-teal" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0%" stop-color="#22d3ee"/>
        <stop offset="100%" stop-color="#069396"/>
      </linearGradient>
      <linearGradient id="mc-iso-navy" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stop-color="#1E3A8A"/>
        <stop offset="100%" stop-color="#012A4A"/>
      </linearGradient>
      <linearGradient id="mc-iso-screen" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stop-color="#0891b2"/>
        <stop offset="100%" stop-color="#069396"/>
      </linearGradient>
      <linearGradient id="mc-iso-patient" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stop-color="#0ea5e9"/>
        <stop offset="100%" stop-color="#0077B6"/>
      </linearGradient>
      <filter id="mc-iso-shadow" x="-15%" y="-15%" width="130%" height="140%">
        <feDropShadow dx="0" dy="10" stdDeviation="12" flood-color="#012A4A" flood-opacity="0.14"/>
      </filter>
      <filter id="mc-ai-glow" x="-50%" y="-50%" width="200%" height="200%">
        <feGaussianBlur stdDeviation="4" result="blur"/>
        <feMerge>
          <feMergeNode in="blur"/>
          <feMergeNode in="SourceGraphic"/>
        </feMerge>
      </filter>
    </defs>

    <ellipse cx="300" cy="424" rx="215" ry="24" fill="#012A4A" opacity="0.1"/>

    <!-- Animated connection lines (behind elements) -->
    <g class="hero-illus__connections" opacity="0.7">
      <path class="hero-illus__conn-line" d="M248 200 C220 175 210 155 208 140" stroke="#069396" stroke-width="1.5" fill="none"/>
      <path class="hero-illus__conn-line hero-illus__conn-line--delay" d="M318 230 C290 200 250 175 228 165" stroke="#0ea5e9" stroke-width="1.5" fill="none"/>
      <path class="hero-illus__conn-line hero-illus__conn-line--delay2" d="M400 155 C370 140 340 130 294 120" stroke="#069396" stroke-width="1.5" fill="none"/>
      <path class="hero-illus__conn-line hero-illus__conn-line--delay3" d="M430 200 C400 210 370 220 358 240" stroke="#0077B6" stroke-width="1.5" fill="none"/>
    </g>

    <!-- Platform -->
    <g class="hero-illus__platform hero-illus__widget" style="--wd:0">
      <path d="M72 332 L498 332 L538 296 L112 296 Z" fill="#EEF6F8"/>
      <path d="M112 296 L538 296 L538 312 L112 312 Z" fill="#D8EBEF"/>
      <path d="M72 332 L112 312 L538 312 L498 332 Z" fill="url(#mc-iso-navy)"/>
      <path d="M72 332 L112 312 L112 296 L72 316 Z" fill="#011f35" opacity="0.35"/>
    </g>

    <g class="hero-illus__plant hero-illus__widget hero-illus__float-slow" style="--wd:1">
      <path d="M98 306 L148 306 L162 294 L112 294 Z" fill="#EEF6F8"/>
      <path d="M112 294 L162 294 L162 302 L112 302 Z" fill="url(#mc-iso-navy)" opacity="0.85"/>
      <path d="M118 290 L152 290 L148 282 L122 282 Z" fill="url(#mc-iso-teal)"/>
      <rect x="122" y="276" width="26" height="8" rx="2" fill="#069396"/>
      <ellipse cx="128" cy="266" rx="10" ry="16" fill="#069396" transform="rotate(-25 128 266)"/>
      <ellipse cx="142" cy="262" rx="10" ry="16" fill="#22d3ee" transform="rotate(15 142 262)"/>
      <ellipse cx="135" cy="256" rx="9" ry="14" fill="#0ea5e9" transform="rotate(-5 135 256)"/>
    </g>

    <g class="hero-illus__location hero-illus__widget hero-illus__float-slow" style="--wd:2">
      <path d="M428 306 L478 306 L492 294 L442 294 Z" fill="#EEF6F8"/>
      <path d="M442 294 L492 294 L492 302 L442 302 Z" fill="url(#mc-iso-navy)" opacity="0.85"/>
      <path d="M455 292 C455 276 475 276 475 292 C475 302 455 316 455 316 C455 316 435 302 435 292 C435 276 455 276 455 292Z" fill="url(#mc-iso-teal)"/>
      <circle cx="455" cy="290" r="7" fill="#fff"/>
    </g>

    <g class="hero-illus__meds hero-illus__widget hero-illus__float-slow" style="--wd:3">
      <rect x="258" y="300" width="14" height="26" rx="4" fill="url(#mc-iso-teal)"/>
      <rect x="258" y="296" width="14" height="6" rx="2" fill="#012A4A" opacity="0.3"/>
      <rect x="278" y="304" width="12" height="22" rx="3" fill="#0ea5e9"/>
    </g>

    <!-- Medical dashboard hub -->
    <g class="hero-illus__hub hero-illus__widget" style="--wd:4" filter="url(#mc-iso-shadow)">
      <path d="M368 172 L408 152 L408 292 L368 312 Z" fill="#D6EEF2"/>
      <path d="M288 152 L368 172 L408 152 L328 132 Z" fill="#fff"/>
      <path d="M288 152 L368 172 L368 312 L288 292 Z" fill="#fff" stroke="rgba(6,147,150,0.12)" stroke-width="1"/>
      <rect x="328" y="122" width="36" height="10" rx="5" fill="#EEF6F8" stroke="rgba(6,147,150,0.2)" stroke-width="1"/>
      <line x1="334" y1="127" x2="354" y2="127" stroke="#069396" stroke-width="2" stroke-linecap="round"/>
      <circle cx="328" cy="222" r="28" fill="rgba(6,147,150,0.1)" stroke="rgba(6,147,150,0.2)" stroke-width="1"/>
      <path d="M312 222 C312 204 344 204 344 222" stroke="#012A4A" stroke-width="3" stroke-linecap="round" fill="none"/>
      <path d="M328 222 C318 222 314 232 314 242 C314 252 322 258 330 258" stroke="#069396" stroke-width="2.5" stroke-linecap="round" fill="none"/>
      <circle cx="330" cy="258" r="5" fill="#069396"/>
      <path d="M328 232 C324 226 316 226 316 234 C316 242 328 250 328 250 C328 250 340 242 340 234 C340 226 332 226 328 232Z" fill="#069396"/>
      <rect x="376" y="182" width="10" height="10" rx="2" fill="#069396"/>
      <rect x="390" y="182" width="10" height="10" rx="2" fill="rgba(6,147,150,0.45)"/>
      <rect x="376" y="198" width="10" height="10" rx="2" fill="rgba(6,147,150,0.35)"/>
      <rect x="390" y="198" width="10" height="10" rx="2" fill="#069396"/>
      <rect x="376" y="214" width="10" height="10" rx="2" fill="rgba(6,147,150,0.45)"/>
      <rect x="390" y="214" width="10" height="10" rx="2" fill="rgba(6,147,150,0.35)"/>
      <rect x="376" y="230" width="10" height="10" rx="2" fill="#069396"/>
      <rect x="390" y="230" width="10" height="10" rx="2" fill="rgba(6,147,150,0.45)"/>
    </g>

    <!-- Medical records -->
    <g class="hero-illus__records hero-illus__widget" style="--wd:5" filter="url(#mc-iso-shadow)">
      <rect x="258" y="192" width="52" height="68" rx="6" fill="#fff" stroke="rgba(6,147,150,0.2)" stroke-width="1.2"/>
      <rect x="272" y="184" width="24" height="10" rx="3" fill="rgba(6,147,150,0.2)"/>
      <rect x="268" y="208" width="32" height="32" rx="4" fill="rgba(6,147,150,0.1)"/>
      <path d="M284 218 V238 M274 228 H294" stroke="#069396" stroke-width="3" stroke-linecap="round"/>
      <line x1="268" y1="248" x2="300" y2="248" stroke="rgba(1,42,74,0.12)" stroke-width="2" stroke-linecap="round"/>
      <line x1="268" y1="256" x2="296" y2="256" stroke="rgba(1,42,74,0.08)" stroke-width="2" stroke-linecap="round"/>
    </g>

    <!-- Doctor video consultation screen -->
    <g class="hero-illus__doctor hero-illus__widget" style="--wd:6" filter="url(#mc-iso-shadow)">
      <path d="M148 112 L268 112 L268 262 L148 262 Z" fill="url(#mc-iso-screen)"/>
      <path d="M148 112 L268 112 L268 132 L148 132 Z" fill="rgba(255,255,255,0.15)"/>
      <rect x="158" y="122" width="100" height="130" rx="8" fill="#fff"/>
      <text x="208" y="138" text-anchor="middle" fill="#608395" font-size="7" font-weight="700" font-family="Segoe UI, sans-serif">Doctor</text>
      <circle cx="208" cy="162" r="24" fill="#E8B89D"/>
      <path d="M180 202 C180 178 236 178 236 202 V220 H180 Z" fill="#fff"/>
      <path d="M184 152 C190 134 226 134 232 152 C226 140 190 140 184 152Z" fill="#3D2314"/>
      <path d="M228 192 C238 192 242 182 242 172" stroke="#012A4A" stroke-width="2" stroke-linecap="round" fill="none"/>
      <circle cx="242" cy="172" r="4" fill="#069396"/>
      <rect x="158" y="236" width="100" height="16" rx="4" fill="rgba(6,147,150,0.12)"/>
      <circle class="hero-illus__video" cx="248" cy="244" r="4" fill="#ef4444"/>
    </g>

    <!-- Patient video consultation screen -->
    <g class="hero-illus__patient hero-illus__widget" style="--wd:7" filter="url(#mc-iso-shadow)">
      <path d="M388 128 L488 128 L488 258 L388 258 Z" fill="url(#mc-iso-patient)"/>
      <path d="M388 128 L488 128 L488 146 L388 146 Z" fill="rgba(255,255,255,0.15)"/>
      <rect x="398" y="136" width="80" height="110" rx="8" fill="#fff"/>
      <text x="438" y="152" text-anchor="middle" fill="#608395" font-size="7" font-weight="700" font-family="Segoe UI, sans-serif">Patient</text>
      <circle cx="438" cy="176" r="20" fill="#E8B89D"/>
      <path d="M418 212 C418 192 458 192 458 212 V226 H418 Z" fill="rgba(14,165,233,0.75)"/>
      <path d="M422 168 C426 154 454 154 458 168 C454 158 426 158 422 168Z" fill="#5C4033"/>
      <rect x="398" y="232" width="80" height="14" rx="4" fill="rgba(0,119,182,0.12)"/>
      <rect x="408" y="236" width="30" height="6" rx="3" fill="rgba(0,119,182,0.35)"/>
    </g>

    <!-- Health monitoring panel -->
    <g class="hero-illus__monitor hero-illus__widget" style="--wd:8" filter="url(#mc-iso-shadow)">
      <rect x="118" y="76" width="92" height="50" rx="10" fill="#fff" stroke="rgba(6,147,150,0.18)" stroke-width="1.2"/>
      <circle cx="132" cy="92" r="3" fill="#22c55e"/>
      <text x="140" y="95" fill="#608395" font-size="7" font-weight="600" font-family="Segoe UI, sans-serif">Vitals Monitor</text>
      <path class="hero-illus__monitor-line" d="M128 108 H140 L146 94 L152 118 L158 100 L164 112 L176 106 H202" stroke="#069396" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
    </g>

    <!-- Healthcare chat icon -->
    <g class="hero-illus__icon-chat hero-illus__widget hero-illus__float-slow" style="--wd:9" filter="url(#mc-iso-shadow)">
      <rect x="278" y="66" width="48" height="40" rx="10" fill="url(#mc-iso-teal)"/>
      <path d="M292 98 L292 106 L284 106 L292 98Z" fill="url(#mc-iso-teal)"/>
      <path d="M302 80 V92 M295 86 H309" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/>
    </g>

    <!-- AI triage indicator with soft glow -->
    <g class="hero-illus__ai hero-illus__widget" style="--wd:10" filter="url(#mc-ai-glow)">
      <ellipse class="hero-illus__ai-glow" cx="452" cy="122" rx="52" ry="24" fill="rgba(6,147,150,0.12)"/>
      <rect x="404" y="104" width="96" height="36" rx="18" fill="#fff" stroke="rgba(6,147,150,0.25)" stroke-width="1.5"/>
      <circle cx="424" cy="122" r="10" fill="rgba(6,147,150,0.15)"/>
      <circle class="hero-illus__ai-dot" cx="424" cy="122" r="4" fill="#069396"/>
      <text x="438" y="119" fill="#012A4A" font-size="8.5" font-weight="800" font-family="Segoe UI, sans-serif">AI Triage</text>
      <text x="438" y="130" fill="#069396" font-size="7.5" font-weight="600" font-family="Segoe UI, sans-serif">Active</text>
    </g>
  </svg>
</div>
