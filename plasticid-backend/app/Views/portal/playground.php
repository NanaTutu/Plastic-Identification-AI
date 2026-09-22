<?= view('portal/_head', ['title' => 'Playground']) ?>
<body>
<?= view('portal/_nav', ['active' => 'playground']) ?>

<header class="page-hero text-center">
    <div class="container">
        <div class="eyebrow grad mb-2">Playground</div>
        <h1>Test the classifier live</h1>
        <p class="sub mx-auto">Upload a photo of plastic waste and get an instant classification &mdash; no API key required.</p>
    </div>
</header>

<section class="section">
    <div class="container" style="max-width: 960px">
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card-modern">
                    <div class="card-body">
                        <h5 class="fw-bold mb-1">Upload an image</h5>
                        <p class="text-muted small mb-3">JPEG or PNG, up to 10 MB. &nbsp;<span class="badge-resin">FREE</span>&nbsp; shared demo key &middot; rate-limited</p>

                        <?php if (isset($error) && $error): ?>
                            <div class="callout callout-danger mb-3">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
                                <div><?= esc($error) ?></div>
                            </div>
                        <?php endif ?>

                        <form method="post" action="/portal/predict" enctype="multipart/form-data" id="pg-form">
                            <?= csrf_field() ?>
                            <label class="dropzone d-block mb-3" for="image" id="dropzone">
                                <span class="dz-icon">
                                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5M12 3v12"/></svg>
                                </span>
                                <div class="fw-semibold" id="dz-text">Drop an image here or <span class="gradient-text fw-bold">browse</span></div>
                                <div class="text-muted small mt-1">Select a plastic bottle, jug, or container photo</div>
                            </label>
                            <input type="file" class="d-none" id="image" name="image" accept="image/jpeg,image/png" required>
                            <button type="submit" class="btn-grad w-100 justify-content-center" id="pg-submit" disabled>
                                Classify Image
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                            </button>
                        </form>

                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <span class="chip" style="color:#0b1220;background:#eef4fb;border-color:#d7e0ee">Max 10 MB</span>
                            <span class="chip" style="color:#0b1220;background:#eef4fb;border-color:#d7e0ee">6 resin classes</span>
                            <span class="chip" style="color:#0b1220;background:#eef4fb;border-color:#d7e0ee">~3 s inference</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card-modern">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3">Reading the results</h5>
                        <div class="d-flex align-items-start gap-3 mb-3">
                            <span class="step-num">1</span>
                            <div>
                                <strong>Plastic type</strong>
                                <p class="text-muted small mb-0">The predicted resin class (HDPE, LDPE, PVC, PET, PP, PS).</p>
                            </div>
                        </div>
                        <div class="d-flex align-items-start gap-3 mb-3">
                            <span class="step-num">2</span>
                            <div>
                                <strong>Confidence</strong>
                                <p class="text-muted small mb-0">Model certainty from 0 to 1 &mdash; higher is more reliable.</p>
                            </div>
                        </div>
                        <div class="d-flex align-items-start gap-3 mb-3">
                            <span class="step-num">3</span>
                            <div>
                                <strong>Bounding box</strong>
                                <p class="text-muted small mb-0">Pixel coordinates <code>[x1, y1, x2, y2]</code> of each detection.</p>
                            </div>
                        </div>
                        <div class="callout callout-info">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto"><path d="M20 12a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z"/><path d="M12 8v4M12 16h.01"/></svg>
                            <div>For best results use a <strong>single-item, well-lit</strong> photo on a plain background.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isset($result) && $result): ?>
            <div class="card-modern mt-4" id="results">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                        <div>
                            <div class="eyebrow grad">Live Result</div>
                            <h4 class="fw-bold mb-0">Results for <?= esc($filename) ?></h4>
                        </div>
                        <a class="btn-outline-dark" href="/portal/playground">Try Another Image</a>
                    </div>

                    <?php if (empty($result['detections'])): ?>
                        <div class="callout callout-note">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto"><path d="M20 12a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z"/><path d="M8 12h8"/></svg>
                            <div><strong>No plastic objects detected</strong> in this image. Try a clearer, single-item photo.</div>
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($result['detections'] as $d): ?>
                                <?php
                                    $name = $d['class_name'] ?? $d['class'] ?? '?';
                                    $conf = round(($d['confidence'] ?? 0) * 100, 1);
                                    $bbox = $d['bbox'] ?? [];
                                ?>
                                <div class="col-md-6">
                                    <div class="card-modern" style="box-shadow:none;background:var(--surface-2)">
                                        <div class="card-body">
                                            <div class="d-flex align-items-center justify-content-between mb-2">
                                                <span class="badge-resin fs-6"><?= esc($name) ?></span>
                                                <span class="fw-bold fs-5" style="color:var(--brand-ink)"><?= esc($conf) ?>%</span>
                                            </div>
                                            <div class="progress" style="height:8px;border-radius:99px;background:#e3eaf5">
                                                <div class="progress-bar rounded-pill" style="width: <?= esc($conf) ?>%; background:var(--brand-grad)"></div>
                                            </div>
                                            <div class="text-muted small mt-2">
                                                <code>[<?= esc(implode(', ', array_map(fn ($v) => round($v), $bbox))) ?>]</code>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach ?>
                        </div>
                        <p class="text-muted small mt-3 mb-0">
                            Inference time: <code><?= esc($result['inference_ms'] ?? '?') ?> ms</code> &middot;
                            objects found: <code><?= esc($result['count'] ?? count($result['detections'])) ?></code>
                        </p>
                    <?php endif ?>
                </div>
            </div>
        <?php endif ?>
    </div>
</section>

<section class="pb-5">
    <div class="container">
        <div class="cta-band py-5">
            <h2 class="fw-bold mb-2">Building an application?</h2>
            <p class="mb-4" style="color:#b6c2da">Get a dedicated API key with your own rate limits and read the full docs.</p>
            <div class="d-flex flex-wrap justify-content-center gap-3">
                <a class="btn-grad" href="/portal/docs">Read the API Docs</a>
                <a class="btn-ghost" href="/portal/request">Get an API Key</a>
            </div>
        </div>
    </div>
</section>

<?= view('portal/_footer') ?>
<script>
    (function () {
        var input = document.getElementById('image');
        var dz = document.getElementById('dropzone');
        var text = document.getElementById('dz-text');
        var submit = document.getElementById('pg-submit');

        if (!input || !dz) return;

        ['dragenter', 'dragover'].forEach(function (ev) {
            dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('dragover'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('dragover'); });
        });
        dz.addEventListener('drop', function (e) {
            if (e.dataTransfer.files && e.dataTransfer.files.length) {
                input.files = e.dataTransfer.files;
                onChange();
            }
        });
        input.addEventListener('change', onChange);

        function onChange() {
            var f = input.files && input.files[0];
            if (f) {
                text.innerHTML = '<span class="gradient-text fw-bold">' + f.name + '</span> <span class="text-muted">(' + Math.round(f.size / 1024) + ' KB)</span>';
                submit.disabled = false;
            } else {
                submit.disabled = true;
            }
        }

        submit.addEventListener('click', function () {
            if (!input.files || !input.files.length) return;
            submit.setAttribute('disabled', 'disabled');
            submit.innerHTML = 'Classifying&hellip;';
        });
    })();
</script>
</body>
</html>