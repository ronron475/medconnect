<?php
/** About Us — capstone milestone section */
$asset = ASSET_BASE;

$projectParagraphs = [
    'We are <strong>Ronald Gonzales</strong>, <strong>Janica Jade Sumagaysay</strong>, and <strong>Joy Gonzaga</strong>, fourth-year Bachelor of Science in Information System (BSIS) students at <strong>Bago City College</strong>. As aspiring Information System professionals, we are passionate about leveraging technology to develop innovative, practical, and user-centered solutions that address real-world challenges and improve the quality of public services.',
    'United by our shared commitment to innovation, collaboration, and community development, we developed <strong>medConnect: An Online Medical Video Call Consultation and AI-Powered Triage System</strong> as our capstone project. This system was designed to support the City Health Office of Bago City by improving healthcare accessibility, streamlining medical consultation workflows, and enhancing patient management through modern digital technologies.',
    'medConnect provides a comprehensive telemedicine platform that integrates <strong>secure online medical video consultations, AI-powered patient triage, intelligent appointment scheduling, centralized electronic medical records (EMR), digital prescriptions, consultation history, follow-up monitoring, notifications, and healthcare reporting tools</strong>. By combining these technologies into a single platform, our goal is to reduce administrative workload, improve communication between patients and healthcare providers, and ensure that essential healthcare services remain accessible, efficient, and well-organized.',
    'Throughout the research, design, development, testing, and implementation of this project, we embraced the principles of teamwork, continuous learning, critical thinking, and problem-solving. We conducted extensive requirement analysis, collaborated with healthcare professionals and stakeholders, and applied industry-standard software engineering practices to develop a reliable, secure, scalable, and user-friendly healthcare information system.',
    'Our capstone journey strengthened not only our technical expertise in <strong>Information Systems, software engineering, database management, web development, artificial intelligence, FastAPI, OCR technology, and system integration</strong>, but also our communication, leadership, project management, and collaborative skills. Every challenge we encountered became an opportunity to learn, improve, and create a solution that delivers meaningful value to both healthcare providers and patients.',
    'Beyond fulfilling an academic requirement, medConnect represents our commitment to using technology as a tool for positive social impact. We believe that digital innovation has the power to transform healthcare delivery, improve operational efficiency, support informed medical decision-making, and bridge the gap between communities and essential healthcare services.',
    'As future Information System professionals, we remain committed to lifelong learning, ethical software development, and the continuous pursuit of technological excellence. We aspire to build reliable, secure, inclusive, and sustainable digital solutions that empower organizations, strengthen public services, and contribute to the advancement of healthcare, innovation, and community development for the benefit of society.',
    'We hope that medConnect serves as a meaningful contribution to the digital transformation initiatives of the City Health Office of Bago City and demonstrates how information technology can improve healthcare accessibility, enhance service delivery, and create lasting positive change for future generations.',
];

?>
<section id="about-section" class="about-team-section services-section" aria-labelledby="about-section-heading">

  <div class="services-container">

    <div class="services-header about-team-section__header">
      <p class="about-team-section__kicker">Bago City College · BSIS Capstone</p>
      <h2 id="about-section-heading" class="services-title landing-reveal-title">About Us</h2>
      <p class="services-desc landing-reveal-desc about-team-section__intro">
        Fourth-year <span class="services-brand">Bachelor of Science in Information System</span> students building
        <span class="services-brand">medConnect</span> to improve healthcare accessibility for the City Health Office of Bago City.
      </p>
    </div>

    <div class="about-team-section__layout">

      <div class="about-team-section__story">
        <div class="about-project-milestone">
          <div class="about-project-milestone__head">
            <div class="about-team-section__story-icon" aria-hidden="true">📜</div>
            <h3 class="about-project-milestone__title-badge">OUR medConnect MILESTONE</h3>
            <p class="about-project-milestone__tagline">Our capstone journey symbolizes growth, dedication, and community impact.</p>
          </div>

          <div class="about-project-milestone__scroll-box"
               id="about-project-milestone"
               aria-label="medConnect project milestones">
            <div class="about-project-milestone__scroll-track" id="about-project-milestone-track">
              <div class="about-project-milestone__scroll-group">
                <?php foreach ($projectParagraphs as $paragraph): ?>
                <div class="about-project-milestone__entry">
                  <p class="about-project-milestone__text"><?= $paragraph ?></p>
                </div>
                <?php endforeach; ?>
              </div>
              <div class="about-project-milestone__scroll-group" aria-hidden="true">
                <?php foreach ($projectParagraphs as $paragraph): ?>
                <div class="about-project-milestone__entry">
                  <p class="about-project-milestone__text"><?= $paragraph ?></p>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="about-team-section__partners">
          <img src="<?= htmlspecialchars($asset) ?>/assets/img/bcclogo.png" alt="Bago City College" class="about-team-section__bcc-logo" width="48" height="48" />
          <div>
            <span class="about-team-section__partners-label">Developed in partnership with</span>
            <span class="about-team-section__partners-name">City Health Office · Bago City</span>
          </div>
        </div>
      </div>

    </div>

  </div>

</section>
