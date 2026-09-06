        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php // Defined here as well as in the tenant footer: the admin console's AJAX
      // forms post the token from the form itself, but a console page that calls
      // one of the shared endpoints would otherwise fail closed with "Invalid
      // request" and the cause would not be obvious. ?>
<script>window.waCsrfToken = '<?= csrfToken() ?>';</script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script src="<?= APP_URL ?>/assets/js/forms.js"></script>
</body>
</html>
