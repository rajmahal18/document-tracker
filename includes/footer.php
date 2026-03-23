    </div>
  </main>

  <footer class="footer">
    <span>© 2026 Ministry of Public Works - BARMM</span>
    <span class="muted">All activities may be monitored.</span>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/pdf-lib/dist/pdf-lib.min.js"></script>
  <script src="<?= ASSETS_PATH ?>/js/app.js?v=<?= time() ?>"></script>
  <script src="<?= ASSETS_PATH ?>/js/view_document_merge.js?v=<?= time() ?>"></script>
  <script src="<?= ASSETS_PATH ?>/js/login.js?v=<?= time() ?>"></script>
  <script src="<?= ASSETS_PATH ?>/js/pwa-ui.js?v=<?= time() ?>"></script>


  <?php if (!empty($pageScripts) && is_array($pageScripts)): ?>
    <?php foreach ($pageScripts as $src): ?>
      <script src="<?= htmlspecialchars((string)$src, ENT_QUOTES, "UTF-8") ?>"></script>
    <?php endforeach; ?>
  <?php endif; ?>

<?php if (isset($_SESSION["user_id"])): ?>
  <script>
    (function () {
      const apiBase = window.__APP__?.api;
      const csrf = window.__APP__?.csrf;
      if (!apiBase || !csrf) return;

      let timer = null;
      let pending = false;

      async function pingHeartbeat() {
        if (pending) return;
        pending = true;
        try {
          await fetch(`${apiBase}/heartbeat.php`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
              'Accept': 'application/json'
            },
            body: `csrf_token=${encodeURIComponent(csrf)}`
          });
        } catch (err) {
          // no-op
        } finally {
          pending = false;
        }
      }

      function startHeartbeat() {
        if (timer) window.clearInterval(timer);
        timer = window.setInterval(pingHeartbeat, 60000);
      }

      document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
          void pingHeartbeat();
        }
      });

      window.addEventListener('focus', () => { void pingHeartbeat(); });

      startHeartbeat();
      window.setTimeout(pingHeartbeat, 4000);
    })();
  </script>
<?php endif; ?>

</body>
</html>
