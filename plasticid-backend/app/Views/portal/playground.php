<?= view('portal/_head', ['title' => 'Playground']) ?>
<body>
<?= view('portal/_nav', ['active' => 'playground']) ?>

<header class="page-hero">
    <div class="container">
        <span class="kicker">Playground</span>
        <h1>Test the classifier live</h1>
        <p class="lede">Upload a photo of plastic waste and get an instant classification, no API key required.</p>
    </div>
</header>

<section class="section" data-reveal>
    <div class="container narrow">
        <div class="grid-2">
            <div>
                <div class="panel">
                    <p class="panel-title">Upload an image</p>
                    <p class="panel-sub">JPEG or PNG, up to 10 MB, using a shared demo key.</p>

                    <?php if (isset($error) && $error): ?>
                        <div class="callout danger" style="margin-bottom:16px">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 15.5h.01"/></svg>
                            <div><?= esc($error) ?></div>
                        </div>
                    <?php endif ?>

                    <form method="post" action="/portal/predict" enctype="multipart/form-data" id="pg-form">
                        <?= csrf_field() ?>
                        <label class="dropzone" for="image" id="dropzone">
                            <span class="dz-icon">
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5M12 3v12"/></svg>
                            </span>
                            <p class="dz-title" id="dz-text">Drop an image here or browse</p>
                            <p class="dz-sub">Pick a bottle, jug, or container photo</p>
                            <input type="file" id="image" name="image" accept="image/jpeg,image/png" required>
                        </label>
                        <button type="submit" class="btn btn-primary" style="width:100%" id="pg-submit" disabled>
                            Classify image
                            <svg class="arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </button>
                    </form>

                    <div class="u-flex u-gap-8 u-wrap" style="margin-top:16px">
                        <span class="tag">10 MB max</span>
                        <span class="tag">6 resin classes</span>
                        <span class="tag">No sign-up</span>
                    </div>
                </div>
            </div>

            <div>
                <div class="panel">
                    <p class="panel-title">Reading the results</p>
                    <dl class="spec-list" style="margin-top:10px">
                        <div class="spec-row">
                            <span class="spec-key">TYPE</span>
                            <div><dt>Plastic type</dt><dd>The predicted resin class: HDPE, LDPE, PVC, PET, PP, or PS.</dd></div>
                        </div>
                        <div class="spec-row">
                            <span class="spec-key">CONF</span>
                            <div><dt>Confidence</dt><dd>Model certainty from 0 to 1; higher is more reliable.</dd></div>
                        </div>
                        <div class="spec-row">
                            <span class="spec-key">BOX</span>
                            <div><dt>Bounding box</dt><dd>Pixel coordinates <code>[x1, y1, x2, y2]</code> of each detection.</dd></div>
                        </div>
                    </dl>
                    <div class="callout info">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 15.5h.01"/></svg>
                        <div>Best results come from a <strong>single-item, well-lit</strong> photo on a plain background.</div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isset($result) && $result): ?>
            <div style="margin-top:36px" id="results">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:18px">
                    <div>
                        <span class="kicker">Live result</span>
                        <h2 style="font-size:1.4rem;margin-top:8px">Results for <?= esc($filename) ?></h2>
                    </div>
                    <a class="btn btn-secondary" href="/portal/playground">Try another image</a>
                </div>

                <?php if (empty($result['detections'])): ?>
                    <div class="callout note">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 12h8"/></svg>
                        <div><strong>No plastic objects detected</strong> in this image. Try a clearer, single-item photo.</div>
                    </div>
                <?php else: ?>
                    <div class="result-grid">
                        <?php foreach ($result['detections'] as $d): ?>
                            <?php
                                $name = $d['class_name'] ?? $d['class'] ?? '?';
                                $conf = round(($d['confidence'] ?? 0) * 100, 1);
                                $bbox = $d['bbox'] ?? [];
                            ?>
                            <div class="result-card">
                                <div class="top">
                                    <span class="tag tag-accent"><?= esc($name) ?></span>
                                    <span class="conf"><?= esc($conf) ?>%</span>
                                </div>
                                <div class="bbox">
                                    <span class="u-muted">bbox &nbsp;</span>
                                    <code>[<?= esc(implode(', ', array_map(fn ($v) => round($v), $bbox))) ?>]</code>
                                </div>
                            </div>
                        <?php endforeach ?>
                    </div>
                    <p class="result-meta">
                        <span>Inference <code><?= esc($result['inference_ms'] ?? '?') ?> ms</code></span>
                        <span>Objects found <code><?= esc($result['count'] ?? count($result['detections'])) ?></code></span>
                    </p>
                <?php endif ?>
            </div>
        <?php endif ?>
    </div>
</section>

<section class="promo" data-reveal>
    <div class="container">
        <div class="inner">
            <div>
                <span class="kicker">Build</span>
                <h2>Building an application?</h2>
                <p>Get a dedicated API key with your own rate limits and read the full docs.</p>
            </div>
            <div class="actions">
                <a class="btn btn-primary" href="/portal/docs">Read the API docs</a>
                <a class="btn btn-secondary" href="/portal/request">Get an API key</a>
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
                text.textContent = f.name + ' (' + Math.round(f.size / 1024) + ' KB)';
                submit.disabled = false;
            } else {
                submit.disabled = true;
            }
        }

        submit.addEventListener('click', function () {
            if (!input.files || !input.files.length) return;
            submit.setAttribute('disabled', 'disabled');
            submit.innerHTML = 'Classifying...';
        });
    })();
</script>
</body>
</html>