<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";

$next = app_safe_next_path((string)($_POST["next"] ?? $_GET["next"] ?? ""), PUBLIC_PATH . "/documents.php");

// If already logged in, continue to the requested internal page.
if (is_logged_in()) {
  redirect($next);
}

$pageTitle = "Login - Document Tracker";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $login = login_with_credentials($conn, (string)($_POST["username"] ?? ""), (string)($_POST["password"] ?? ""));
  if (empty($login["ok"])) {
    $error = (string)($login["error"] ?? "Invalid login credentials.");
  } else {
    if ((int)($login["must_change_password"] ?? 0) === 1) {
      redirect(PUBLIC_PATH . "/change_password.php");
    }
    redirect($next);
  }
}

require __DIR__ . "/../includes/layout.php";
?>

<div class="grid">
  <section class="card">
    <h2>Document Tracking System</h2>

    <div class="notice">
      Authorized personnel only. All activities in this system are logged.
    </div>

    <?php if ($error): ?>
      <div class="notice" style="background:#f8d7da;border:1px solid #f5c2c7;">
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <form class="authForm" method="POST" action="<?= PUBLIC_PATH ?>/login.php" novalidate>
      <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES, "UTF-8") ?>">

      <div class="authField">
        <label for="username">Username / Email</label>
        <input
          id="username"
          type="text"
          name="username"
          placeholder="Enter your username or email"
          value="<?= htmlspecialchars((string)($_POST["username"] ?? "")) ?>"
          autocomplete="username"
          required
        >
      </div>

      <div class="authField">
        <label for="password">Password</label>
        <input
          id="password"
          type="password"
          name="password"
          placeholder="Enter your password"
          autocomplete="current-password"
          required
        >
      </div>

      <div class="authRow">
        <label class="authCheck">
          <input type="checkbox" name="remember">
          <span>Keep me signed in</span>
        </label>

        <a class="authLink" href="#" onclick="event.preventDefault()">
          Forgot password?
        </a>
      </div>

      <button type="submit" class="authBtn">Login</button>

      <p class="authHelp">
        Need access? You can request an account
        <button type="button" class="linkButton" onclick="openAccessModal()">here</button>
      </p>
    </form>
  </section>

  <aside class="aside">
    <div class="asideBox">
      <p class="asideTitle">Reminders</p>
      <ul>
        <li>Do not share your login credentials.</li>
        <li>Always logout after use on shared computers.</li>
        <li>For official transactions only.</li>
      </ul>
    </div>

    <div class="asideMeta">
      <span class="badge">Official Use Only</span>
      <span class="muted">v0.1</span>
    </div>
  </aside>
</div>

<div id="accessModal" class="modalWrap" aria-hidden="true">
  <div class="modalBackdrop" onclick="closeAccessModal()"></div>

  <div class="modalCard" role="dialog" aria-modal="true" aria-labelledby="accessModalTitle">
    <div class="modalHeader">
      <h3 id="accessModalTitle">Request System Access</h3>
      <button type="button" class="modalClose" onclick="closeAccessModal()" aria-label="Close">&times;</button>
    </div>

    <form id="accessForm" class="modalBody" onsubmit="submitAccessRequest(event)">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, "UTF-8") ?>">

      <div class="authField">
        <label>Full Name</label>
        <input name="full_name" type="text" placeholder="Enter your full name" required maxlength="150">
      </div>

      <div class="authField">
        <label>Office / Section</label>
        <input name="office_section" type="text" placeholder="Enter your office or section" required maxlength="150">
      </div>

      <div class="authField">
        <label>Email</label>
        <input name="email" type="text" placeholder="Enter your official email" required maxlength="190">
      </div>

      <div class="authField">
        <label>Reason for Access</label>
        <textarea name="reason" class="modalTextarea" placeholder="Briefly state the reason" required maxlength="500"></textarea>
      </div>

      <div id="accessMsg" class="modalMsg" style="display:none;"></div>
    </form>

    <div class="modalFooter">
      <button type="button" class="btnComp" onclick="closeAccessModal()">Cancel</button>
      <button type="submit" class="btnSecondary" form="accessForm">Submit Request</button>
    </div>
  </div>
</div>

<?php require __DIR__ . "/../includes/footer.php"; ?>
