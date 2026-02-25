<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";

// If already logged in, go to documents
if (is_logged_in()) {
  redirect(PUBLIC_PATH . "/documents.php");
}

$pageTitle = "Login - Document Tracker";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $username = trim($_POST["username"] ?? "");
  $password = $_POST["password"] ?? "";

  if ($username === "" || $password === "") {
    $error = "Please enter your username/email and password.";
  } else {

    // ✅ Pull user + section + division for session UI
    $stmt = $conn->prepare("
      SELECT
        u.id,
        u.full_name,
        u.email,
        u.password_hash,
        u.must_change_password,
        u.role,
        u.section_id,
        s.name AS section_name,
        d.name AS division_name
      FROM users u
      LEFT JOIN sections s ON s.id = u.section_id
      LEFT JOIN divisions d ON d.id = s.division_id
      WHERE u.email = ?
      LIMIT 1
    ");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user || !password_verify($password, (string)$user["password_hash"])) {
      $error = "Invalid login credentials.";
    } else {
      // ✅ store everything needed in session
      $_SESSION["user_id"]       = (int)$user["id"];
      $_SESSION["full_name"]     = (string)$user["full_name"];
      $_SESSION["role"]          = (string)($user["role"] ?? "user");
      $_SESSION["must_change_password"] = (int)($user["must_change_password"] ?? 0);

      $_SESSION["section_id"]    = isset($user["section_id"]) ? (int)$user["section_id"] : null;
      $_SESSION["section_name"]  = (string)($user["section_name"] ?? "");
      $_SESSION["division_name"] = (string)($user["division_name"] ?? "");

      if ((int)($_SESSION["must_change_password"] ?? 0) === 1) {
        redirect(PUBLIC_PATH . "/change_password.php");
      }

      redirect(PUBLIC_PATH . "/documents.php");
    }
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
      <div class="authField">
        <label for="username">Username / Email</label>
        <input
          id="username"
          type="text"
          name="username"
          placeholder="Enter your username or email"
          value="<?= htmlspecialchars($_POST["username"] ?? "") ?>"
          autocomplete="username"
          required
        />
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
        />
      </div>

      <div class="authRow">
        <label class="authCheck">
          <input type="checkbox" name="remember" />
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
          <button type="button" class="modalClose" onclick="closeAccessModal()" aria-label="Close">✕</button>
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
