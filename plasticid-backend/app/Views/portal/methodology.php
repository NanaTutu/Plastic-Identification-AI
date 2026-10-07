<?= view('portal/_head', ['title' => 'Methodology']) ?>
<body>
<?= view('portal/_nav', ['active' => 'methodology']) ?>

<header class="page-hero">
    <div class="container">
        <span class="kicker">Methodology</span>
        <h1>How PlasticID works under the hood</h1>
        <p class="lede">A two-stage computer-vision pipeline that localizes objects first, then identifies the resin.</p>
    </div>
</header>

<section class="section" data-reveal>
    <div class="container">
        <div class="section-head">
            <span class="kicker">The pipeline</span>
            <h2 class="title-lg">Detect, crop, classify</h2>
            <p class="lede">Classifying arbitrary photos directly is unreliable; many images hold several objects and a busy background. PlasticID splits the two hard problems apart.</p>
        </div>

        <div class="pipeline" style="margin-top:44px">
            <div class="pipe">
                <span class="step-tick">01</span>
                <h3>Input image</h3>
                <p>JPEG or PNG up to 10 MB, any resolution.</p>
            </div>
            <div class="pipe-arrow">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </div>
            <div class="pipe">
                <span class="step-tick">02</span>
                <h3>Object detection</h3>
                <p>YOLOv8n (COCO) locates bottles, cups, bowls and vases, drawing a bounding box around each one.</p>
            </div>
            <div class="pipe-arrow">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </div>
            <div class="pipe is-final">
                <span class="step-tick">03</span>
                <h3>Resin classification</h3>
                <p>The best object is cropped and passed to a YOLOv8 model fine-tuned on the six plastic classes.</p>
            </div>
        </div>

        <p class="code-note">
            <code>/v1/predict</code> runs all three stages in one call and returns the class, confidence, and bounding box per detection.
        </p>
    </div>
</section>

<section class="section band" data-reveal>
    <div class="container">
        <div class="section-head">
            <span class="kicker">Supported plastics</span>
            <h2 class="title-lg">Six resin classes, clearly separated</h2>
        </div>
        <?= view('portal/_plastics') ?>
    </div>
</section>

<section class="section" data-reveal>
    <div class="container">
        <div class="section-head">
            <span class="kicker">Model and training</span>
            <h2 class="title-lg">Built on YOLOv8, tuned for plastics</h2>
        </div>

        <div class="grid-2" style="margin-top:38px">
            <div class="panel" style="box-shadow:none">
                <h3 style="font-size:1.2rem;margin-bottom:6px">The classifier</h3>
                <dl class="spec-list">
                    <div class="spec-row">
                        <span class="spec-key">MODEL</span>
                        <div><dt>Architecture</dt><dd>YOLOv8, fine-tuned from <code>yolov8n.pt</code></dd></div>
                    </div>
                    <div class="spec-row">
                        <span class="spec-key">INPUT</span>
                        <div><dt>Inference size</dt><dd><code>640 &times; 640</code> px</dd></div>
                    </div>
                    <div class="spec-row">
                        <span class="spec-key">TRAIN</span>
                        <div><dt>Optimizer</dt><dd>Adam with learning rate <code>0.001</code></dd></div>
                    </div>
                    <div class="spec-row">
                        <span class="spec-key">EPOCHS</span>
                        <div><dt>Schedule</dt><dd>50 epochs default, patience <code>10</code></dd></div>
                    </div>
                    <div class="spec-row">
                        <span class="spec-key">FLOOR</span>
                        <div><dt>Confidence threshold</dt><dd>Detections below <code>0.25</code> are discarded</dd></div>
                    </div>
                </dl>
            </div>
            <div class="panel" style="box-shadow:none">
                <h3 style="font-size:1.2rem;margin-bottom:6px">The dataset</h3>
                <dl class="spec-list">
                    <div class="spec-row">
                        <span class="spec-key">DATA</span>
                        <div><dt>Source</dt><dd>Labeled images of plastic items per resin class</dd></div>
                    </div>
                    <div class="spec-row">
                        <span class="spec-key">SPLIT</span>
                        <div><dt>Partitioning</dt><dd>Separate <code>train</code> / <code>val</code> / <code>test</code> sets</dd></div>
                    </div>
                    <div class="spec-row">
                        <span class="spec-key">EVAL</span>
                        <div><dt>Tracking</dt><dd>Per-class detection accuracy measured each run</dd></div>
                    </div>
                    <div class="spec-row">
                        <span class="spec-key">METRICS</span>
                        <div><dt>Reporting</dt><dd><code>mAP50</code>, <code>mAP50-95</code>, per-class AP logged to CSV</dd></div>
                    </div>
                </dl>
            </div>
        </div>

        <div class="callout note" style="margin-top:28px">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 15.5h.01"/></svg>
            <div>
                <strong>Training workflow.</strong>
                Configure <code>plasticid/configs/train.yaml</code> and run
                <code>python -m src.training.train</code>. The best checkpoint is copied to
                <code>models/best_v8m.pt</code> automatically, ready for the API to serve.
            </div>
        </div>
    </div>
</section>

<section class="section band" data-reveal>
    <div class="container">
        <div class="section-head">
            <span class="kicker">Output format</span>
            <h2 class="title-lg">Structured, predictable JSON</h2>
            <p class="lede">Each response carries a unique <code>job_id</code> plus one entry per detection.</p>
        </div>

        <div class="code-block" style="margin-top:34px;max-width:780px">
            <button class="code-copy" type="button">Copy</button>
<pre>{
  "job_id": "a1b2c3d4-7f2e-4a6d-9cbe-2f9a8b0c1d2e",
  "model": "plasticid_v1",
  "count": 1,
  "detections": [
    {
      "class_name": "PET",
      "confidence": 0.921,
      "bbox": [64.5, 82.1, 340.2, 511.7]
    }
  ],
  "inference_ms": 412,
  "detected_object": "bottle"
}</pre>
        </div>
        <p class="code-note">
            <code>bbox</code> is pixel coordinates <code>[x1, y1, x2, y2]</code>, and <code>detected_object</code> is the source item found by the detector (for example <em>bottle</em>).
        </p>
    </div>
</section>

<section class="section" data-reveal>
    <div class="container">
        <div class="section-head">
            <span class="kicker">Limitations</span>
            <h2 class="title-lg">When to expect the best results</h2>
        </div>

        <div class="callout warn" style="margin-top:28px">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 16.5h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
            <div>Best accuracy comes from <strong>single-item, well-lit</strong> photos on a plain background.</div>
        </div>

        <div class="grid-2" style="margin-top:28px">
            <div>
                <dl class="spec-list">
                    <div class="spec-row">
                        <span class="spec-key">01</span>
                        <div><dt>Cluttered scenes</dt><dd>Heavy occlusion or busy backgrounds reduce detection reliability.</dd></div>
                    </div>
                    <div class="spec-row">
                        <span class="spec-key">02</span>
                        <div><dt>Low confidence</dt><dd>Detections below <code>0.25</code> are dropped, so not everything gets reported.</dd></div>
                    </div>
                    <div class="spec-row">
                        <span class="spec-key">03</span>
                        <div><dt>CPU inference</dt><dd>Response time varies with image content and resolution.</dd></div>
                    </div>
                </dl>
            </div>
            <div class="bento-card" style="box-shadow:none;user-select:none">
                <span class="kicker">Try it</span>
                <h3>See it in action</h3>
                <p class="lede">Upload a photo on the playground, no sign-up needed.</p>
                <a class="btn btn-primary" href="/portal/playground">
                    Try the playground
                    <svg class="arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
            </div>
        </div>
    </div>
</section>

<section class="promo" data-reveal>
    <div class="container">
        <div class="inner">
            <div>
                <span class="kicker">Build</span>
                <h2>Build on top of the API</h2>
                <p>Documentation for every endpoint, or a key to get you started.</p>
            </div>
            <div class="actions">
                <a class="btn btn-primary" href="/portal/docs">Read the API docs</a>
                <a class="btn btn-secondary" href="/portal/request">Get an API key</a>
            </div>
        </div>
    </div>
</section>

<?= view('portal/_footer') ?>
</body>
</html>