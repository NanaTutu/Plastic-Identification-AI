<?php
$apiBaseUrl = rtrim((string) (getenv('FASTAPI_PUBLIC_URL') ?: 'http://localhost:8000'), '/');
$apiDocsUrl = $apiBaseUrl . '/docs';
?>
<?= view('portal/_head', ['title' => 'API Docs']) ?>
<body>
<?= view('portal/_nav', ['active' => 'docs']) ?>

<header class="page-hero">
    <div class="container">
        <span class="kicker">API reference</span>
        <h1>REST endpoints, models and errors</h1>
        <p class="lede">One consistent API for plastic classification: header-based auth, structured errors, and transparent rate limits.</p>
        <div class="actions">
            <a class="btn btn-primary" href="/portal/playground">
                Try the playground
                <svg class="arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </a>
            <a class="btn btn-secondary" href="<?= esc($apiDocsUrl) ?>" target="_blank" rel="noopener">View Swagger UI</a>
        </div>
    </div>
</header>

<section class="section" data-reveal>
    <div class="container">
        <div class="docs">
            <nav class="toc" aria-label="On this page">
                <ul class="toc">
                    <li><a href="#base-url">Base URL</a></li>
                    <li><a href="#auth">Auth</a></li>
                    <li><a href="#endpoints">Endpoints</a></li>
                    <li><a href="#predict">Predict</a></li>
                    <li><a href="#manage">Key management</a></li>
                    <li><a href="#errors">Errors</a></li>
                    <li><a href="#limits">Rate limits</a></li>
                </ul>
            </nav>

            <div class="doc-content">
                <div class="doc-section" id="base-url">
                    <span class="doc-anchor">Base URL</span>
                    <h3>Where the API lives</h3>
                    <div class="code-block">
                        <button class="code-copy" type="button">Copy</button>
<pre><?= esc($apiBaseUrl) ?></pre>
                    </div>
                    <p class="code-note">An interactive OpenAPI explorer (Swagger UI) is served at <a href="<?= esc($apiDocsUrl) ?>" target="_blank" rel="noopener">/docs</a>.</p>
                </div>

                <div class="doc-section" id="auth">
                    <span class="doc-anchor">Auth</span>
                    <h3>One header, nothing else</h3>
                    <p class="lede" style="font-size:1.02rem">Send your key in the <code>X-API-KEY</code> header on every request.</p>
                    <div class="code-block" style="margin-top:18px">
                        <button class="code-copy" type="button">Copy</button>
<pre>GET /v1/usage
X-API-KEY: pk_your_key_here</pre>
                    </div>
                    <div class="callout warn" style="margin-top:18px">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 16.5h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
                        <div>Never put your key in a query string; it leaks into web server and proxy logs. Use the header.</div>
                    </div>
                    <p class="code-note">Need a key? <a href="/portal/request">Request one free</a>, or test instantly on the <a href="/portal/playground">playground</a>.</p>
                </div>

                <div class="doc-section" id="endpoints">
                    <span class="doc-anchor">Endpoints</span>
                    <h3>Every route at a glance</h3>
                    <div class="table-scroll">
                        <table class="spec-table">
                            <thead>
                                <tr><th>Method</th><th>Path</th><th>Auth</th><th>Purpose</th></tr>
                            </thead>
                            <tbody>
                                <tr><td><span class="tag tag-accent">POST</span></td><td class="path">/v1/predict</td><td>API key</td><td>Detect, then classify</td></tr>
                                <tr><td><span class="tag tag-accent">GET</span></td><td class="path">/v1/predictions</td><td>API key</td><td>Recent prediction summary</td></tr>
                                <tr><td><span class="tag tag-accent">GET</span></td><td class="path">/v1/usage</td><td>API key</td><td>Your limits and usage</td></tr>
                                <tr><td><span class="tag tag-accent">POST</span></td><td class="path">/v1/keys</td><td>Master</td><td>Create an API key</td></tr>
                                <tr><td><span class="tag tag-accent">GET</span></td><td class="path">/v1/list_keys</td><td>Master</td><td>List keys, masked</td></tr>
                                <tr><td><span class="tag tag-accent">DELETE</span></td><td class="path">/v1/keys/{key}</td><td>Master</td><td>Deactivate a key</td></tr>
                                <tr><td><span class="tag tag-accent">GET</span></td><td class="path">/v1/stats</td><td>Master</td><td>Global usage statistics</td></tr>
                                <tr><td><span class="tag tag-accent">GET</span></td><td class="path">/ and /health/db</td><td class="u-muted">None</td><td>Health checks</td></tr>
                                <tr><td><span class="tag tag-accent">GET</span></td><td class="path">/metrics</td><td class="u-muted">None</td><td>Prometheus metrics</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="doc-section" id="predict">
                    <span class="doc-anchor">Predict</span>
                    <h3>POST /v1/predict</h3>
                    <p class="lede" style="font-size:1.02rem">Upload a JPEG or PNG (max 10 MB) as multipart field <code>image</code>.</p>
                    <div class="code-block" style="margin-top:18px">
                        <button class="code-copy" type="button">Copy</button>
<pre>curl -X POST "<?= esc($apiBaseUrl) ?>/v1/predict" \
  -H "X-API-KEY: pk_your_key_here" \
  -F "image=@plastic_bottle.jpg"</pre>
                    </div>
                    <p style="margin:26px 0 12px;font-family:var(--font-mono);font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--faint)">Response, 200 OK</p>
                    <div class="code-block">
                        <button class="code-copy" type="button">Copy</button>
<pre>{
  "job_id": "3f9c1e2a-6d7b-4a8e-9c5b-2d1f0a9e8c7d",
  "model": "plasticid_v1",
  "count": 1,
  "detections": [
    {
      "class_name": "PET",
      "confidence": 0.9214,
      "bbox": [64.5, 82.1, 340.2, 511.7]
    }
  ],
  "inference_ms": 412,
  "detected_object": "bottle"
}</pre>
                    </div>
                    <p class="code-note">Headers <code>X-RateLimit-Limit</code>, <code>X-RateLimit-Remaining</code>, and <code>X-RateLimit-Reset</code> disclose your current quota.</p>
                </div>

                <div class="doc-section" id="manage">
                    <span class="doc-anchor">Key management</span>
                    <h3>POST /v1/keys</h3>
                    <p class="lede" style="font-size:1.02rem">The master key comes from the <code>API_KEY</code> environment variable. Management endpoints are rate-limited to <strong>10 requests per 60 seconds</strong> per client, and generated keys are stored hashed. They are returned to you only once.</p>
                    <div class="code-block" style="margin-top:18px">
                        <button class="code-copy" type="button">Copy</button>
<pre>curl -X POST "http://localhost:8000/v1/keys" \
  -H "X-API-KEY: &lt;master_key&gt;" \
  -H "Content-Type: application/json" \
  -d '{"owner": "my_application", "rate_limit": 100, "window_seconds": 3600}'</pre>
                    </div>
                </div>

                <div class="doc-section" id="errors">
                    <span class="doc-anchor">Errors</span>
                    <h3>Consistent, machine-readable errors</h3>
                    <div class="code-block">
                        <button class="code-copy" type="button">Copy</button>
<pre>{
  "error": {
    "code": "rate_limit_exceeded",
    "message": "Rate limit exceeded. Retry in 60s",
    "retry_after": 60
  }
}</pre>
                    </div>

                    <div class="table-scroll" style="margin-top:24px">
                        <table class="spec-table">
                            <thead>
                                <tr><th>Code</th><th>HTTP</th><th>Meaning</th></tr>
                            </thead>
                            <tbody>
                                <tr><td class="path">API_KEY_REQUIRED</td><td><span class="tag tag-accent">401</span></td><td>No <code>X-API-KEY</code> header sent</td></tr>
                                <tr><td class="path">INVALID_API_KEY</td><td><span class="tag tag-accent">401</span></td><td>Unknown, invalid, or deactivated key</td></tr>
                                <tr><td class="path">INVALID_IMAGE</td><td><span class="tag tag-accent">400</span></td><td>File is not a decodable JPEG or PNG</td></tr>
                                <tr><td class="path">INVALID_OWNER</td><td><span class="tag tag-accent">400</span></td><td>Key owner name missing or too long</td></tr>
                                <tr><td class="path">KEY_NOT_FOUND</td><td><span class="tag tag-accent">404</span></td><td>Key to deactivate was not found</td></tr>
                                <tr><td class="path">FILE_TOO_LARGE</td><td><span class="tag tag-accent">413</span></td><td>Upload exceeds the 10 MB limit</td></tr>
                                <tr><td class="path">rate_limit_exceeded</td><td><span class="tag tag-accent">429</span></td><td>Quota exhausted for this window</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="doc-section" id="limits">
                    <span class="doc-anchor">Rate limits</span>
                    <h3>How quotas behave</h3>
                    <dl class="spec-list">
                        <div class="spec-row">
                            <span class="spec-key">01</span>
                            <div><dt>Per key</dt><dd>Each key has a <code>rate_limit</code> per <code>window_seconds</code>.</dd></div>
                        </div>
                        <div class="spec-row">
                            <span class="spec-key">02</span>
                            <div><dt>Exceeded</dt><dd>Returns <code>429</code> with a <code>retry_after</code> value.</dd></div>
                        </div>
                        <div class="spec-row">
                            <span class="spec-key">03</span>
                            <div><dt>Counting</dt><dd>Prediction requests count against the key quota.</dd></div>
                        </div>
                        <div class="spec-row">
                            <span class="spec-key">04</span>
                            <div><dt>Management</dt><dd>Master-key management endpoints are limited independently.</dd></div>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="promo" data-reveal>
    <div class="container">
        <div class="inner">
            <div>
                <span class="kicker">Build</span>
                <h2>Ready to build?</h2>
                <p>Generate an API key and integrate PlasticID in minutes.</p>
            </div>
            <div class="actions">
                <a class="btn btn-primary" href="/portal/request">Get an API key</a>
                <a class="btn btn-secondary" href="/portal/playground">Try the playground</a>
            </div>
        </div>
    </div>
</section>

<?= view('portal/_footer') ?>
<script>
    (function () {
        var links = Array.prototype.slice.call(document.querySelectorAll('.toc a'));
        var map = {};
        links.forEach(function (a) {
            var id = a.getAttribute('href');
            if (id) map[id.slice(1)] = a;
        });

        function set(active) {
            links.forEach(function (a) { a.classList.remove('on'); });
            if (map[active]) map[active].classList.add('on');
        }

        var root = document.querySelector('.doc-content');
        if (!root || !('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        var toggleable = Object.keys(map);
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) set(entry.target.id);
            });
        }, { rootMargin: '-20% 0px -70% 0px' });

        root.querySelectorAll('.doc-section').forEach(function (s) {
            if (toggleable.indexOf(s.id) !== -1) io.observe(s);
        });
    })();
</script>
</body>
</html>