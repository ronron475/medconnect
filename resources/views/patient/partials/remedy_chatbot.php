<div
  id="ptRemedyChat"
  class="pt-remedy"
  data-open="false"
  aria-live="polite"
  hidden
>
  <button type="button" class="pt-remedy__fab" id="ptRemedyFab" aria-expanded="false" aria-controls="ptRemedyPanel" hidden>
    <span class="pt-remedy__fab-dot" aria-hidden="true"></span>
    Care tips
  </button>

  <section
    class="pt-remedy__panel"
    id="ptRemedyPanel"
    role="dialog"
    aria-modal="true"
    aria-labelledby="ptRemedyTitle"
    aria-hidden="true"
    hidden
  >
    <header class="pt-remedy__header">
      <div>
        <p class="pt-remedy__eyebrow">medConnect Care Assistant</p>
        <h2 id="ptRemedyTitle" class="pt-remedy__title">Self-care guidance</h2>
      </div>
      <div class="pt-remedy__header-actions">
        <button
          type="button"
          class="pt-remedy__voice"
          id="ptRemedyVoice"
          aria-label="Read messages aloud"
          title="Read messages aloud"
          aria-pressed="false"
          hidden
        >
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M11 5L6 9H3v6h3l5 4V5z"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/>
          </svg>
        </button>
        <button
          type="button"
          class="pt-remedy__close"
          id="ptRemedyClose"
          aria-label="Close care chat"
          onclick="if(window.MedConnectPtRemedy){window.MedConnectPtRemedy.close(event);}"
        >×</button>
      </div>
    </header>

    <div class="pt-remedy__thread" id="ptRemedyThread" aria-live="polite"></div>

    <div class="pt-remedy__choices" id="ptRemedyChoicesWaiting" hidden>
      <p class="pt-remedy__choice-label">Your provider reviews non-urgent concerns before tips are shared.</p>
      <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="pt-remedy__choice pt-remedy__choice--primary" id="ptRemedyBookWaiting">
        Book a consultation
      </a>
      <button
        type="button"
        class="pt-remedy__choice pt-remedy__choice--outline"
        id="ptRemedyWaitClose"
        onclick="if(window.MedConnectPtRemedy){window.MedConnectPtRemedy.close(event);}"
      >
        Close for now
      </button>
    </div>

    <div class="pt-remedy__choices" id="ptRemedyChoicesApproved" hidden>
      <p class="pt-remedy__choice-label">What would you like to do?</p>
      <button type="button" class="pt-remedy__choice pt-remedy__choice--primary" id="ptRemedySelfCare">
        I’ll follow the self-care tips
      </button>
      <button type="button" class="pt-remedy__choice pt-remedy__choice--danger" id="ptRemedyCancelVisit" hidden>
        Cancel my video visit
      </button>
      <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="pt-remedy__choice pt-remedy__choice--outline" id="ptRemedyBook">
        Book a consultation
      </a>
    </div>
  </section>
</div>

<div
  id="mcCarePlanAcceptedModal"
  class="mc-tips-ready-modal"
  hidden
  role="dialog"
  aria-modal="true"
  aria-labelledby="mcCarePlanAcceptedTitle"
  aria-describedby="mcCarePlanAcceptedMessage"
>
  <div class="mc-tips-ready-modal__backdrop" data-mc-care-plan-dismiss></div>
  <div class="mc-tips-ready-modal__card" role="document">
    <div class="mc-tips-ready-modal__icon" aria-hidden="true">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
        <polyline points="22 4 12 14.01 9 11.01"/>
      </svg>
    </div>
    <p class="mc-tips-ready-modal__eyebrow">Care guidance</p>
    <h2 class="mc-tips-ready-modal__title" id="mcCarePlanAcceptedTitle">Care Plan Accepted</h2>
    <p class="mc-tips-ready-modal__message" id="mcCarePlanAcceptedMessage">
      You can follow the doctor-approved care tips at home. Your current health concern remains saved in your health record. You can still book a consultation anytime if you want to speak with a doctor about this concern.
    </p>
    <div class="mc-tips-ready-modal__actions">
      <a href="<?= ASSET_BASE ?>/views/patient/dashboard.php" class="mc-tips-ready-modal__btn mc-tips-ready-modal__btn--primary" id="mcCarePlanDashboardBtn">
        Continue to Dashboard
      </a>
      <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="mc-tips-ready-modal__btn mc-tips-ready-modal__btn--outline" id="mcCarePlanBookBtn">
        Book Consultation
      </a>
    </div>
  </div>
</div>
