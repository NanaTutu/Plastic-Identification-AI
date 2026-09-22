<footer class="site-footer">
    <div class="container d-flex flex-wrap align-items-center justify-content-between gap-3">
        <span class="text-muted small mb-0">♻ PlasticID &mdash; AI-Powered Plastic Classification</span>
        <div class="footer-links small">
            <a href="/portal/methodology">Methodology</a>
            <a href="/portal/docs">API Docs</a>
            <a href="/portal/playground">Playground</a>
            <a href="/portal/request">Get API Key</a>
        </div>
    </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.querySelector('.nav-toggle');
        var links = document.getElementById('portalNav');
        if (toggle && links) {
            toggle.addEventListener('click', function () {
                links.classList.toggle('open');
                toggle.setAttribute('aria-expanded', links.classList.contains('open'));
            });
        }

        document.querySelectorAll('.code-copy').forEach(function (btn) {
            var block = btn.closest('.code-block');
            if (!block) return;
            btn.addEventListener('click', function () {
                var code = block.querySelector('pre');
                var text = code ? code.innerText : '';
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(text).then(function () {
                        btn.textContent = 'Copied';
                        btn.classList.add('copied');
                        setTimeout(function () { btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 1600);
                    });
                }
            });
        });
    });
</script>