<?php
/** FAB popup modals — summary content + link to full landing sections */
$annItems = array_slice($landing_announcements ?? [], 0, 4);
?>
<div class="fab-modal-root" id="fab-modal-root" aria-hidden="true">
  <!-- Announcements -->
  <div
    class="fab-modal"
    id="fab-modal-announcements"
    role="dialog"
    aria-modal="true"
    aria-labelledby="fab-modal-announcements-title"
    hidden
  >
    <div class="fab-modal__overlay" data-fab-modal-close tabindex="-1"></div>
    <div class="fab-modal__dialog">
      <header class="fab-modal__header">
        <div>
          <h2 class="fab-modal__title" id="fab-modal-announcements-title">Announcements</h2>
          <p class="fab-modal__subtitle">Latest updates from the City Health Office of Bago City.</p>
        </div>
        <button type="button" class="fab-modal__close" data-fab-modal-close aria-label="Close">&times;</button>
      </header>
      <div class="fab-modal__body">
        <?php if (empty($annItems)): ?>
          <p class="fab-modal__empty">No announcements are available right now. Check back soon for health advisories and program updates.</p>
        <?php else: ?>
          <ul class="fab-modal__list">
            <?php foreach ($annItems as $ann): ?>
              <li class="fab-modal__card">
                <span class="fab-modal__card-accent" aria-hidden="true"></span>
                <div>
                  <?php if (!empty($ann['category_label'])): ?>
                    <span class="fab-modal__card-tag"><?= htmlspecialchars((string) $ann['category_label']) ?></span>
                  <?php endif; ?>
                  <h3 class="fab-modal__card-title"><?= htmlspecialchars((string) $ann['title']) ?></h3>
                  <?php if (!empty($ann['short_description'])): ?>
                    <p class="fab-modal__card-desc"><?= htmlspecialchars((string) $ann['short_description']) ?></p>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <footer class="fab-modal__footer">
        <a href="#announcements-section" class="fab-modal__cta" data-fab-scroll="announcements-section">View all announcements</a>
      </footer>
    </div>
  </div>

  <!-- Services -->
  <div
    class="fab-modal"
    id="fab-modal-services"
    role="dialog"
    aria-modal="true"
    aria-labelledby="fab-modal-services-title"
    hidden
  >
    <div class="fab-modal__overlay" data-fab-modal-close tabindex="-1"></div>
    <div class="fab-modal__dialog">
      <header class="fab-modal__header">
        <div>
          <h2 class="fab-modal__title" id="fab-modal-services-title">Our Services</h2>
          <p class="fab-modal__subtitle">Healthcare access and digital tools for non-emergency care through medConnect.</p>
        </div>
        <button type="button" class="fab-modal__close" data-fab-modal-close aria-label="Close">&times;</button>
      </header>
      <div class="fab-modal__body">
        <div class="fab-modal__grid">
          <article class="fab-modal__card">
            <span class="fab-modal__card-accent" aria-hidden="true"></span>
            <h3 class="fab-modal__card-title">AI-Assisted Triage</h3>
            <p class="fab-modal__card-desc">Smart symptom assessment helps classify patients based on urgency.</p>
          </article>
          <article class="fab-modal__card">
            <span class="fab-modal__card-accent" aria-hidden="true"></span>
            <h3 class="fab-modal__card-title">Medical Video Consultation</h3>
            <p class="fab-modal__card-desc">Secure video calls for remote consultations, reducing unnecessary travel.</p>
          </article>
          <article class="fab-modal__card">
            <span class="fab-modal__card-accent" aria-hidden="true"></span>
            <h3 class="fab-modal__card-title">Centralized Medical Records</h3>
            <p class="fab-modal__card-desc">All patient information stored in one secure digital platform.</p>
          </article>
          <article class="fab-modal__card">
            <span class="fab-modal__card-accent" aria-hidden="true"></span>
            <h3 class="fab-modal__card-title">Post-Consultation Monitoring</h3>
            <p class="fab-modal__card-desc">Track patient progress and schedule follow-ups after consultation.</p>
          </article>
        </div>
      </div>
      <footer class="fab-modal__footer">
        <a href="#services-section" class="fab-modal__cta" data-fab-scroll="services-section">Explore services on page</a>
      </footer>
    </div>
  </div>

  <!-- How It Works -->
  <div
    class="fab-modal"
    id="fab-modal-how-it-works"
    role="dialog"
    aria-modal="true"
    aria-labelledby="fab-modal-how-title"
    hidden
  >
    <div class="fab-modal__overlay" data-fab-modal-close tabindex="-1"></div>
    <div class="fab-modal__dialog">
      <header class="fab-modal__header">
        <div>
          <h2 class="fab-modal__title" id="fab-modal-how-title">How It Works</h2>
          <p class="fab-modal__subtitle">A simple four-step process for non-emergency healthcare access.</p>
        </div>
        <button type="button" class="fab-modal__close" data-fab-modal-close aria-label="Close">&times;</button>
      </header>
      <div class="fab-modal__body">
        <ol class="fab-modal__steps">
          <li class="fab-modal__step">
            <span class="fab-modal__step-num">1</span>
            <div>
              <h3 class="fab-modal__card-title">Register &amp; Verify</h3>
              <p class="fab-modal__card-desc">Create your patient account and verify your identity securely.</p>
            </div>
          </li>
          <li class="fab-modal__step">
            <span class="fab-modal__step-num">2</span>
            <div>
              <h3 class="fab-modal__card-title">AI-Assisted Triage</h3>
              <p class="fab-modal__card-desc">Complete symptom assessment to help prioritize your care needs.</p>
            </div>
          </li>
          <li class="fab-modal__step">
            <span class="fab-modal__step-num">3</span>
            <div>
              <h3 class="fab-modal__card-title">Video Consultation</h3>
              <p class="fab-modal__card-desc">Connect with a licensed provider through secure video consultation.</p>
            </div>
          </li>
          <li class="fab-modal__step">
            <span class="fab-modal__step-num">4</span>
            <div>
              <h3 class="fab-modal__card-title">Records &amp; Follow-Up</h3>
              <p class="fab-modal__card-desc">Consultation notes are saved and follow-up care can be scheduled.</p>
            </div>
          </li>
        </ol>
      </div>
      <footer class="fab-modal__footer">
        <a href="#how-it-works" class="fab-modal__cta" data-fab-scroll="how-it-works">See full guide on page</a>
      </footer>
    </div>
  </div>

  <!-- About -->
  <div
    class="fab-modal"
    id="fab-modal-about"
    role="dialog"
    aria-modal="true"
    aria-labelledby="fab-modal-about-title"
    hidden
  >
    <div class="fab-modal__overlay" data-fab-modal-close tabindex="-1"></div>
    <div class="fab-modal__dialog">
      <header class="fab-modal__header">
        <div>
          <h2 class="fab-modal__title" id="fab-modal-about-title">About Us</h2>
          <p class="fab-modal__subtitle">BSIS capstone project by Bago City College students for the City Health Office of Bago City.</p>
        </div>
        <button type="button" class="fab-modal__close" data-fab-modal-close aria-label="Close">&times;</button>
      </header>
      <div class="fab-modal__body">
        <div class="fab-modal__grid">
          <article class="fab-modal__card">
            <span class="fab-modal__card-accent" aria-hidden="true"></span>
            <h3 class="fab-modal__card-title">Our Team</h3>
            <p class="fab-modal__card-desc">Ronald Gonzales, Janica Jade Sumagaysay, and Joy Gonzaga — fourth-year BSIS students at Bago City College.</p>
          </article>
          <article class="fab-modal__card">
            <span class="fab-modal__card-accent" aria-hidden="true"></span>
            <h3 class="fab-modal__card-title">medConnect</h3>
            <p class="fab-modal__card-desc">An online medical video consultation and AI-powered triage system designed to improve healthcare accessibility for Bago City.</p>
          </article>
          <article class="fab-modal__card">
            <span class="fab-modal__card-accent" aria-hidden="true"></span>
            <h3 class="fab-modal__card-title">Our Mission</h3>
            <p class="fab-modal__card-desc">Streamline consultations, centralize medical records, and strengthen follow-up care through modern digital health tools.</p>
          </article>
          <article class="fab-modal__card">
            <span class="fab-modal__card-accent" aria-hidden="true"></span>
            <h3 class="fab-modal__card-title">Partnership</h3>
            <p class="fab-modal__card-desc">Developed in partnership with the City Health Office of Bago City as a capstone contribution to public healthcare.</p>
          </article>
        </div>
      </div>
      <footer class="fab-modal__footer">
        <a href="#about-section" class="fab-modal__cta" data-fab-scroll="about-section">Read our full story</a>
      </footer>
    </div>
  </div>

  <!-- Contact -->
  <div
    class="fab-modal"
    id="fab-modal-contact"
    role="dialog"
    aria-modal="true"
    aria-labelledby="fab-modal-contact-title"
    hidden
  >
    <div class="fab-modal__overlay" data-fab-modal-close tabindex="-1"></div>
    <div class="fab-modal__dialog">
      <header class="fab-modal__header">
        <div>
          <h2 class="fab-modal__title" id="fab-modal-contact-title">Contact Information</h2>
          <p class="fab-modal__subtitle">Reach the City Health Office of Bago City for medConnect support and inquiries.</p>
        </div>
        <button type="button" class="fab-modal__close" data-fab-modal-close aria-label="Close">&times;</button>
      </header>
      <div class="fab-modal__body">
        <div class="fab-modal__grid fab-modal__grid--contact">
          <article class="fab-modal__card fab-modal__card--contact">
            <span class="fab-modal__card-accent" aria-hidden="true"></span>
            <h3 class="fab-modal__card-title">Office Location</h3>
            <p class="fab-modal__card-desc">City Health Office, Bago City, Negros Occidental</p>
          </article>
          <article class="fab-modal__card fab-modal__card--contact">
            <span class="fab-modal__card-accent" aria-hidden="true"></span>
            <h3 class="fab-modal__card-title">Phone</h3>
            <p class="fab-modal__card-desc"><a href="tel:+63344458000">(034) 445-8000</a></p>
          </article>
          <article class="fab-modal__card fab-modal__card--contact">
            <span class="fab-modal__card-accent" aria-hidden="true"></span>
            <h3 class="fab-modal__card-title">Email</h3>
            <p class="fab-modal__card-desc"><a href="mailto:cho.bagocity@example.gov.ph">cho.bagocity@example.gov.ph</a></p>
          </article>
          <article class="fab-modal__card fab-modal__card--contact">
            <span class="fab-modal__card-accent" aria-hidden="true"></span>
            <h3 class="fab-modal__card-title">Office Hours</h3>
            <p class="fab-modal__card-desc">Mon – Fri, 8:00 AM – 5:00 PM</p>
          </article>
        </div>
        <p class="fab-modal__note">
          For medical emergencies, go to the nearest healthcare facility or call emergency services immediately.
        </p>
      </div>
      <footer class="fab-modal__footer">
        <a href="#contact-section" class="fab-modal__cta" data-fab-scroll="contact-section">View contact section</a>
      </footer>
    </div>
  </div>
</div>
