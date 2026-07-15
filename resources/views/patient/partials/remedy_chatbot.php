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
      <button type="button" class="pt-remedy__close" id="ptRemedyClose" aria-label="Close care chat">×</button>
    </header>

    <div class="pt-remedy__thread" id="ptRemedyThread" aria-live="polite"></div>

    <div class="pt-remedy__choices" id="ptRemedyChoices" hidden>
      <p class="pt-remedy__choice-label">What would you like to do?</p>
      <button type="button" class="pt-remedy__choice pt-remedy__choice--primary" id="ptRemedySelfCare">
        I’ll follow the self-care tips
      </button>
      <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="pt-remedy__choice pt-remedy__choice--outline" id="ptRemedyBook">
        Book a consultation instead
      </a>
    </div>
  </section>
</div>
