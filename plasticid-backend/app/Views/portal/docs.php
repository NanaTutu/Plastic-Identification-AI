<?= view('portal/_head', ['title' => 'API Docs']) ?>
<body>
<?= view('portal/_nav', ['active' => 'docs']) ?>

<header class="page-hero text-center">
    <div class="container">
        <div class="eyebrow grad mb-2">API Reference</div>
        <h1>REST endpoints, models &amp; errors</h1>
        <p class="sub mx-auto">One consistent API for plastic classification &mdash; header-based auth, structured errors, and transparent rate limits.</p>
        <div class="mt-4 d-flex flex-wrap justify-content-center gap-3">
            <a class="btn-grad" href="/portal/playground">Try It Live</a>
            <a class="btn-ghost" href="http://localhost:8000/docs" target="_blank" rel="noopener">Open Swagger UI</a>
        </div>
    </div>
</header>

<section class="section">
    <div class="container" style="max-width: 920px">
        <div class="text-center mb-5">
            <div class="d-flex flex-wrap justify-content-center">
                <a class="toc-pill" href="#base-url">Base URL</a>
                <a class="toc-pill" href="#auth">Auth</a>
                <a class="toc-pill" href="#endpoints">Endpoints</a>
                <a class="toc-pill" href="#predict">Predict</a>
                <a class="toc-pill" href="#legacy">Legacy</a>
                <a class="toc-pill" href="#manage">Key Mgmt</a>
                <a class="toc-pill" href="#errors">Errors</a>
            </div>
        </div>

        <div class="mb-5" id="base-url">
            <div class="eyebrow grad mb-2">Base URL</div>
            <h3 class="fw-bold mb-3">Where the API lives</h3>
            <div class="code-block">
                <button class="code-copy" type="button">Copy</button>
<pre>http://localhost:8000</pre>
            </div>
            <p class="text-muted small mt-2">An interactive OpenAPI explorer (Swagger UI) is served at <a href="http://localhost:8000/docs" target="_blank" rel="noopener">/docs</a>.</p>
        </div>

        <div class="mb-5" id="auth">
            <div class="eyebrow grad mb-2">Authentication</div>
            <h3 class="fw-bold mb-3">One header, nothing else</h3>
            <p class="lead-muted">Send your key in the <code>X-API-KEY</code> header on every request.</p>
            <div class="code-block">
                <button class="code-copy" type="button">Copy</button>
<pre>GET /v1/usage
X-API-KEY: pk_your_key_here</pre>
            </div>
            <div class="callout callout-warn mt-3">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
                <div>Never put your key in a query string &mdash; it leaks into web server and proxy logs. Use the header.</div>
            </div>
            <p class="mt-3 text-muted">Need a key? <a href="/portal/request">Request one free</a>, or test instantly on the <a href="/portal/playground">playground</a>.</p>
        </div>

        <div class="mb-5" id="endpoints">
            <div class="eyebrow grad mb-2">Endpoints</div>
            <h3 class="fw-bold mb-3">Every route at a glance</h3>
            <div class="table-modern table-responsive">
                <table class="w-100">
                    <thead>
                        <tr><th>Method</th><th>Path</th><th>Auth</th><th>Purpose</th></tr>
                    </thead>
                    <tbody>
                        <tr><td><span class="badge-resin">POST</span></td><td><code>/v1/predict</code></td><td>API key</td><td>Detect &rarr; crop &rarr; classify</td></tr>
                        <tr><td><span class="badge-resin">GET</span></td><td><code>/v1/predictions</code></td><td>API key</td><td>Recent prediction summary</td></tr>
                        <tr><td><span class="badge-resin">GET</span></td><td><code>/v1/usage</code></td><td>API key</td><td>Your limits &amp; usage</td></tr>
                        <tr><td><span class="badge-resin">POST</span></td><td><code>/v1/keys</code></td><td>Master</td><td>Create an API key</td></tr>
                        <tr><td><span class="badge-resin">GET</span></td><td><code>/v1/list_keys</code></td><td>Master</td><td>List keys (masked)</td></tr>
                        <tr><td><span class="badge-resin">DELETE</span></td><td><code>/v1/keys/{key}</code></td><td>Master</td><td>Deactivate a key</td></tr>
                        <tr><td><span class="badge-resin">GET</span></td><td><code>/v1/stats</code></td><td>Master</td><td>Global usage statistics</td></tr>
                        <tr><td><span class="badge-resin">GET</span></td><td><code>/</code> &middot; <code>/health/db</code></td><td>&mdash;</td><td>Health checks</td></tr>
                        <tr><td><span class="badge-resin">GET</span></td><td><code>/metrics</code></td><td>&mdash;</td><td>Prometheus metrics</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mb-5" id="predict">
            <div class="eyebrow grad mb-2">Classify</div>
            <h3 class="fw-bold mb-1">POST /v1/predict</h3>
            <p class="lead-muted mb-3">Upload a JPEG or PNG (max 10 MB) as multipart field <code>image</code>.</p>
            <div class="code-block">
                <button class="code-copy" type="button">Copy</button>
<pre>curl -X POST "http://localhost:8000/v1/predict" \
  -H "X-API-KEY: pk_your_key_here" \
  -F "image=@plastic_bottle.jpg"</pre>
            </div>
            <h6 class="fw-bold mt-4 mb-2">Response &mdash; <span class="text-success">200 OK</span></h6>
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
            <p class="text-muted small mt-2">Headers <code>X-RateLimit-Limit</code>, <code>X-RateLimit-Remaining</code> and <code>X-RateLimit-Reset</code> disclose your current quota.</p>
        </div>

        <div class="mb-5" id="manage">
            <div class="eyebrow grad mb-2">Key Management</div>
            <h3 class="fw-bold mb-1">POST /v1/keys <span class="fs-6 text-muted">(master key)</span></h3>
            <p class="lead-muted mb-3">
                The master key comes from the <code>API_KEY</code> environment variable. Management endpoints are
                rate-limited to <strong>10 requests per 60 seconds</strong> per client, and generated keys are stored
                hashed (returned to you only once).
            </p>
            <div class="code-block">
                <button class="code-copy" type="button">Copy</button>
<pre>curl -X POST "http://localhost:8000/v1/keys" \
  -H "X-API-KEY: &lt;master_key&gt;" \
  -H "Content-Type: application/json" \
  -d '{"owner": "my_application", "rate_limit": 100, "window_seconds": 3600}'</pre>
            </div>
        </div>

        <div id="errors">
            <div class="eyebrow grad mb-2">Error Handling</div>
            <h3 class="fw-bold mb-3">Consistent, machine-readable errors</h3>
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
            <div class="table-modern table-responsive mt-3">
                <table class="w-100">
                    <thead>
                        <tr><th>Code</th><th>HTTP</th><th>Meaning</th></tr>
                    </thead>
                    <tbody>
                        <tr><td><code>API_KEY_REQUIRED</code></td><td><span class="badge-resin">401</span></td><td>No <code>X-API-KEY</code> header sent</td></tr>
                        <tr><td><code>INVALID_API_KEY</code></td><td><span class="badge-resin">401</span></td><td>Unknown, invalid, or deactivated key</td></tr>
                        <tr><td><code>INVALID_IMAGE</code></td><td><span class="badge-resin">400</span></td><td>File is not a decodable JPEG/PNG</td></tr>
                        <tr><td><code>INVALID_OWNER</code></td><td><span class="badge-resin">400</span></td><td>Key owner name missing or too long</td></tr>
                        <tr><td><code>KEY_NOT_FOUND</code></td><td><span class="badge-resin">404</span></td><td>Key to deactivate was not found</td></tr>
                        <tr><td><code>FILE_TOO_LARGE</code></td><td><span class="badge-resin">413</span></td><td>Upload exceeds the 10 MB limit</td></tr>
                        <tr><td><code>rate_limit_exceeded</code></td><td><span class="badge-resin">429</span></td><td>Quota exhausted for this window</td></tr>
                    </tbody>
                </table>
            </div>
            <h3 class="fw-bold mt-5 mb-2">Rate limits</h3>
            <ul class="lead-muted" style="line-height:2">
                <li>Each key has a <code>rate_limit</code> per <code>window_seconds</code>.</li>
                <li>Exceeding the limit returns <code>429</code> with a <code>retry_after</code> value.</li>
                <li>Both prediction endpoints share the same quota per key.</li>
                <li>Master-key management endpoints are limited independently.</li>
            </ul>
        </div>
    </div>
</section>

<section class="pb-5">
    <div class="container">
        <div class="cta-band py-5">
            <h2 class="fw-bold mb-2">Ready to build?</h2>
            <p class="mb-4" style="color:#b6c2da">Generate an API key and integrate PlasticID in minutes.</p>
            <div class="d-flex flex-wrap justify-content-center gap-3">
                <a class="btn-grad" href="/portal/request">Get an API Key</a>
                <a class="btn-ghost" href="/portal/playground">Try the Playground First</a>
            </div>
        </div>
    </div>
</section>

<?= view('portal/_footer') ?>
</body>
</html>