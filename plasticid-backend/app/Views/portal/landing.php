<?= view('portal/_head', ['title' => 'AI-Powered Plastic Classification']) ?>
<body>
<?= view('portal/_nav', ['active' => 'home']) ?>

<header class="hero text-center py-5">
    <div class="container">
        <div class="pt-4">
            <span class="hero-badge">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 3v12M5 8l3 4 4-6 4 8 3-6"/>
                </svg>
                Computer Vision Deep Learning
            </span>
        </div>
        <h1 class="hero-title mt-3 mb-3">Identify plastic types<br><span class="gradient-text">instantly from a photo</span></h1>
        <p class="hero-sub mx-auto mb-4">PlasticID classifies <strong>HDPE, LDPE, PVC, PET, PP &amp; PS</strong> waste with a YOLOv8 two-stage pipeline &mdash; object detection, then resin-level classification.</p>
        <div class="d-flex flex-wrap justify-content-center gap-3 mb-4">
            <a class="btn-grad" href="/portal/playground">
                Try the Playground
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </a>
            <a class="btn-ghost" href="/portal/methodology">Read the Methodology</a>
        </div>
        <div class="hero-chips pb-4">
            <span class="chip"><span class="dot"></span>6 Resin Classes</span>
            <span class="chip"><span class="dot"></span>2-Stage Pipeline</span>
            <span class="chip"><span class="dot"></span>&le; 10 MB Images</span>
            <span class="chip"><span class="dot"></span>Confidence Scoring</span>
        </div>
    </div>
</header>

<section class="section">
    <div class="container">
        <div class="text-center mb-5">
            <div class="eyebrow grad mb-2">How It Works</div>
            <h2 class="section-title">Three fast steps to a classification</h2>
            <p class="section-lead">A purpose-built pipeline turns a raw photo into structured, actionable data.</p>
        </div>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="card-modern">
                    <div class="card-body">
                        <div class="icon-tile">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.4-3.4a2 2 0 0 0-2.8 0L6 20"/></svg>
                        </div>
                        <h5 class="fw-bold mb-2">1 · Upload an image</h5>
                        <p class="text-muted mb-0">Send a JPEG or PNG (up to 10 MB) via the API, playground, or curl.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card-modern">
                    <div class="card-body">
                        <div class="icon-tile">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12H4M8 7l-5 5 5 5M16 7l5 5-5 5"/></svg>
                        </div>
                        <h5 class="fw-bold mb-2">2 · Detect &amp; classify</h5>
                        <p class="text-muted mb-0">A YOLOv8 model locates the object, crops it, then a fine-tuned model identifies the resin.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card-modern">
                    <div class="card-body">
                        <div class="icon-tile">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h10M4 18h7"/><path d="m15 18 2 2 4-4"/></svg>
                        </div>
                        <h5 class="fw-bold mb-2">3 · Get structured results</h5>
                        <p class="text-muted mb-0">JSON with the plastic type, confidence score, bounding box, and timing.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="text-center mb-5">
            <div class="eyebrow grad mb-2">Explore</div>
            <h2 class="section-title">Everything you need to get started</h2>
        </div>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="card-modern">
                    <div class="card-body d-flex flex-column">
                        <span class="step-num mb-3">01</span>
                        <h5 class="fw-bold">Methodology</h5>
                        <p class="text-muted flex-grow-1">Dive into the two-stage pipeline, model training, dataset, confidence thresholds, and known limitations.</p>
                        <a class="btn-outline-dark w-100 justify-content-center" href="/portal/methodology">
                            Read More
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card-modern">
                    <div class="card-body d-flex flex-column">
                        <span class="step-num mb-3">02</span>
                        <h5 class="fw-bold">API Docs</h5>
                        <p class="text-muted flex-grow-1">Every endpoint, authentication flow, request/response examples, error codes, and rate-limit rules.</p>
                        <a class="btn-outline-dark w-100 justify-content-center" href="/portal/docs">
                            View Docs
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card-modern">
                    <div class="card-body d-flex flex-column">
                        <span class="step-num mb-3">03</span>
                        <h5 class="fw-bold">Playground</h5>
                        <p class="text-muted flex-grow-1">Upload a photo of plastic waste right now and see live predictions &mdash; no API key required.</p>
                        <a class="btn-grad w-100 justify-content-center" href="/portal/playground">
                            Try It Now
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="row g-5 align-items-center">
            <div class="col-lg-5">
                <div class="eyebrow grad mb-2">Resin Classes</div>
                <h2 class="section-title">Six common plastics, one fast classifier</h2>
                <p class="lead-muted">Each class corresponds to a standard resin identification code &mdash; the categories that matter most for recycling and waste sorting.</p>
                <a class="btn-grad-dark" href="/portal/playground">Test With Your Own Photo</a>
            </div>
            <div class="col-lg-7">
                <div class="table-responsive">
                    <table class="table-modern">
                        <thead>
                            <tr><th>ID</th><th>Type</th><th>Common Uses</th></tr>
                        </thead>
                        <tbody>
                            <tr><td class="text-muted fw-semibold">0</td><td><span class="badge-resin">HDPE</span></td><td>Milk jugs, shampoo and detergent bottles</td></tr>
                            <tr><td class="text-muted fw-semibold">1</td><td><span class="badge-resin">LDPE</span></td><td>Plastic bags, film, squeeze bottles</td></tr>
                            <tr><td class="text-muted fw-semibold">2</td><td><span class="badge-resin">PVC</span></td><td>Pipes, flooring, window frames</td></tr>
                            <tr><td class="text-muted fw-semibold">3</td><td><span class="badge-resin">PET</span></td><td>Drink bottles, food trays, fiber</td></tr>
                            <tr><td class="text-muted fw-semibold">4</td><td><span class="badge-resin">PP</span></td><td>Caps, lids, containers, straws</td></tr>
                            <tr><td class="text-muted fw-semibold">5</td><td><span class="badge-resin">PS</span></td><td>Cutlery, cups, foam packaging</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="pb-5">
    <div class="container">
        <div class="cta-band py-5">
            <h2 class="fw-bold mb-2">Ready to classify plastic waste?</h2>
            <p class="mb-4" style="color:#b6c2da">Try it with your own image in seconds, or grab an API key and start building.</p>
            <div class="d-flex flex-wrap justify-content-center gap-3">
                <a class="btn-grad" href="/portal/playground">Open Playground</a>
                <a class="btn-ghost" href="/portal/request">Get an API Key</a>
            </div>
        </div>
    </div>
</section>

<?= view('portal/_footer') ?>
</body>
</html>