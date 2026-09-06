        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php // Every mutating AJAX endpoint checks this token, so it is defined here
      // rather than per page: a new page that calls one of them would otherwise
      // fail closed with "Invalid request" and the cause would not be obvious. ?>
<script>window.waCsrfToken = '<?= csrfToken() ?>';</script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<?php // Progressive enhancement only — modals, AJAX submits, confirmations and
      // sticky tabs. Every form it upgrades still posts and validates without it. ?>
<script src="<?= APP_URL ?>/assets/js/forms.js"></script>
</body>
</html>
