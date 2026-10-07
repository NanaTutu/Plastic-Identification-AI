<footer class="site-footer">
    <div class="container">
        <div class="inner">
            <div>
                <span class="fbrand">
                    <span class="fmark">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 12a9 9 0 1 1-9-9"/><path d="M21 3v6h-6"/>
                        </svg>
                    </span>
                    PlasticID
                </span>
                <p>AI-powered plastic classification for recycling and waste sorting.</p>
            </div>
            <nav class="footer-links" aria-label="Footer">
                <a href="/portal/methodology">Methodology</a>
                <a href="/portal/docs">API Docs</a>
                <a href="/portal/playground">Playground</a>
                <a href="/portal/request">Get API key</a>
            </nav>
        </div>
    </div>
</footer>
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

        var scroller = document.querySelector('.site-nav');
        if (scroller) {
            var onScroll = function () { scroller.classList.toggle('scrolled', window.scrollY > 8); };
            window.addEventListener('scroll', onScroll, { passive: true });
            onScroll();
        }

        document.querySelectorAll('.code-block .code-copy, .key-box .code-copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var root = btn.closest('.code-block') || btn.closest('.key-box');
                if (!root) return;
                var code = root.querySelector('pre, .key-value');
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

        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var items = document.querySelectorAll('[data-reveal]');
        if (reduceMotion || !('IntersectionObserver' in window)) {
            items.forEach(function (el) { el.classList.add('is-visible'); });
        } else {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        io.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
            items.forEach(function (el) { io.observe(el); });
        }
    });
</script>