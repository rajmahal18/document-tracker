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

      $_SESSION["section_id"]    = isset($user["section_id"]) ? (int)$user["section_id"] : null;
      $_SESSION["section_name"]  = (string)($user["section_name"] ?? "");
      $_SESSION["division_name"] = (string)($user["division_name"] ?? "");

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

    <form method="POST" action="<?= PUBLIC_PATH ?>/login.php">
      <label>Username / Email</label>
      <input
        type="text"
        name="username"
        placeholder="Enter your username"
        value="<?= htmlspecialchars($_POST["username"] ?? "") ?>"
      />

      <label>Password</label>
      <input type="password" name="password" placeholder="Enter your password" />

      <div class="row">
        <label class="checkbox">
          <input type="checkbox" name="remember" />
          <span>Keep me signed in</span>
        </label>

        <a class="link" href="#" onclick="event.preventDefault()">
          Forgot password?
        </a>
      </div>

      <button type="submit">Login</button>

      <p class="help">
        Having trouble? Contact the System Administrator / ICT Unit.
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

<?php require __DIR__ . "/../includes/footer.php"; ?>
