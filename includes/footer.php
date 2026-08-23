</main>

<footer class="footer mt-auto py-3 bg-light border-top">
    <div class="container-fluid px-3 px-sm-4 text-center">
        <span class="text-muted footer-text">&copy; <?= date('Y') ?> <?= sanitize(APP_CAMPUS) ?> — <?= sanitize(APP_NAME) ?> v<?= APP_VERSION ?></span>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
</body>
</html>
