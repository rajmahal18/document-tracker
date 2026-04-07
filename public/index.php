<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['user_id'])) {
    header('Location: documents.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="theme-color" content="#08111f">
  <title>MPW Document Tracker</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/landing-page.css?v=1">
</head>
<body>
  <div class="landingShell">
    <div class="landingGlow landingGlowA"></div>
    <div class="landingGlow landingGlowB"></div>
    <div class="landingGrid"></div>

    <header class="landingTopbar">
      <a href="index.php" class="landingBrand" aria-label="MPW Document Tracker home">
        <span class="landingBrandMark">
          <img src="../assets/mpwlogo1.png" alt="Ministry of Public Works logo">
        </span>
        <span class="landingBrandText">
          <strong>MPW Document Tracker</strong>
          <span>Ministry of Public Works · BARMM</span>
        </span>
      </a>

      <a href="login.php" class="landingLoginBtn">Login</a>
    </header>

    <main class="landingMain">
      <section class="heroSection">
        <div class="heroCopy">
          <span class="eyebrow">Internal government document workflow platform</span>
          <h1>Track every document.<br>See every handoff.<br>Move with clarity.</h1>
          <p class="heroLead">
            A modern internal platform for routing, acknowledgements, branching, and accountability across offices,
            divisions, and sections inside the Ministry of Public Works.
          </p>

          <div class="heroActions">
            <a href="login.php" class="heroPrimaryBtn">Proceed to Login</a>
          </div>

          <div class="heroMetaRow">
            <div class="heroMetaCard">
              <span class="heroMetaLabel">Built for</span>
              <strong>Technical Services</strong>
              <p>Internal document movement with cleaner visibility and less follow-up friction.</p>
            </div>
            <div class="heroMetaCard">
              <span class="heroMetaLabel">Operational focus</span>
              <strong>Routing + accountability</strong>
              <p>From creation to receipt, every action stays easier to monitor and verify.</p>
            </div>
          </div>
        </div>

        <div class="heroVisual" aria-hidden="true">
          <div class="heroPanel heroPanelMain">
            <div class="heroPanelTop">
              <div>
                <span class="mockLabel">Live workflow snapshot</span>
                <h2>Document routing overview</h2>
              </div>
              <span class="signalPill">Operational</span>
            </div>

            <div class="trackingCard">
              <div>
                <span class="tinyLabel">Tracking no.</span>
                <strong>DOC-2026-00127</strong>
              </div>
              <span class="statusPill statusPillAccent">In circulation</span>
            </div>

            <div class="flowRail">
              <div class="flowNode isActive">
                <span class="flowDot"></span>
                <div>
                  <strong>Director Office</strong>
                  <p>Originating office</p>
                </div>
              </div>
              <div class="flowConnector"></div>
              <div class="flowNode isActive">
                <span class="flowDot"></span>
                <div>
                  <strong>Planning &amp; Programming</strong>
                  <p>Received · with latest remarks</p>
                </div>
              </div>
              <div class="flowConnector isMuted"></div>
              <div class="flowNode">
                <span class="flowDot"></span>
                <div>
                  <strong>Survey &amp; Design</strong>
                  <p>Pending acknowledgement</p>
                </div>
              </div>
            </div>

            <div class="heroStatsStrip">
              <div>
                <span>Recipients</span>
                <strong>06</strong>
              </div>
              <div>
                <span>Received</span>
                <strong>04</strong>
              </div>
              <div>
                <span>Awaiting</span>
                <strong>02</strong>
              </div>
            </div>
          </div>

          <div class="heroPanel heroPanelSide">
            <div class="heroPanelTop compact">
              <div>
                <span class="mockLabel">Visibility</span>
                <h3>What the system makes clear</h3>
              </div>
            </div>

            <div class="insightList">
              <div class="insightItem">
                <span class="insightBullet isBlue"></span>
                <div>
                  <strong>Latest remarks</strong>
                  <p>Visible to the right people across sender, forwarder, and receiver flows.</p>
                </div>
              </div>
              <div class="insightItem">
                <span class="insightBullet isOrange"></span>
                <div>
                  <strong>Branch-aware tracking</strong>
                  <p>Multiple destinations are easier to inspect without losing accountability.</p>
                </div>
              </div>
              <div class="insightItem">
                <span class="insightBullet isGreen"></span>
                <div>
                  <strong>Timeline and slips</strong>
                  <p>Audit history, division slips, and QR-supported referencing stay aligned.</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section class="featureBand">
        <article class="featureCard">
          <span class="featureKicker">01</span>
          <h3>Track document movement end to end</h3>
          <p>Monitor where a document started, where it went, and which office already acknowledged receipt.</p>
        </article>
        <article class="featureCard">
          <span class="featureKicker">02</span>
          <h3>Keep routing structured and visible</h3>
          <p>Support clean handoffs across offices, divisions, and sections with clearer operational context.</p>
        </article>
        <article class="featureCard">
          <span class="featureKicker">03</span>
          <h3>Reduce follow-up friction</h3>
          <p>Surface status, latest remarks, and recipient progress without relying on scattered manual checking.</p>
        </article>
      </section>

      <section class="capabilitySection">
        <div class="sectionHeading">
          <span class="sectionEyebrow">Core capabilities</span>
          <h2>Purpose-built for internal government workflow</h2>
          <p>The platform is positioned around movement, acknowledgement, traceability, and responsibility.</p>
        </div>

        <div class="capabilityGrid">
          <article class="capabilityItem">
            <span>Branch-based routing</span>
            <p>Send documents across multiple destinations while keeping handoff history readable.</p>
          </article>
          <article class="capabilityItem">
            <span>Receive acknowledgement</span>
            <p>Make it easier to see who already received and who still has pending action.</p>
          </article>
          <article class="capabilityItem">
            <span>Timeline / audit trail</span>
            <p>Preserve document events and operational context for cleaner monitoring and follow-up.</p>
          </article>
          <article class="capabilityItem">
            <span>Role-aware visibility</span>
            <p>Keep relevant document information available to the proper users in the workflow.</p>
          </article>
          <article class="capabilityItem">
            <span>Division tracking slips</span>
            <p>Support office and division-level references for day-to-day routing operations.</p>
          </article>
          <article class="capabilityItem">
            <span>Assistant mode and QR support</span>
            <p>Extend operational flexibility while preserving referenceability and official flow.</p>
          </article>
        </div>
      </section>

      <section class="workflowSection">
        <div class="workflowBoard">
          <div class="sectionHeading compactLight">
            <span class="sectionEyebrow">Workflow rhythm</span>
            <h2>Simple at a glance. Accountable in practice.</h2>
          </div>

          <div class="workflowSteps">
            <article class="workflowStep">
              <span class="stepBadge">1</span>
              <h3>Create or receive</h3>
              <p>Start the record with the proper document details and reference trail.</p>
            </article>
            <article class="workflowStep">
              <span class="stepBadge">2</span>
              <h3>Route or forward</h3>
              <p>Move documents to the right destination while preserving clarity on recipients.</p>
            </article>
            <article class="workflowStep">
              <span class="stepBadge">3</span>
              <h3>Acknowledge and monitor</h3>
              <p>Track receipt, latest remarks, and pending responses from one operational view.</p>
            </article>
          </div>
        </div>
      </section>
    </main>
  </div>
</body>
</html>
