<?= view('portal/_head', ['title' => 'Methodology']) ?>
<body>
<?= view('portal/_nav', ['active' => 'methodology']) ?>

<header class="page-hero text-center">
    <div class="container">
        <div class="eyebrow grad mb-2">Methodology</div>
        <h1>How PlasticID works under the hood</h1>
        <p class="sub mx-auto">A two-stage computer-vision pipeline that localizes objects, then identifies the resin.</p>
    </div>
</header>

<section class="section">
    <div class="container">
        <div class="text-center mb-5">
            <div class="eyebrow grad mb-2">The Pipeline</div>
            <h2 class="section-title">Detect &rarr; Crop &rarr; Classify</h2>
            <p class="section-lead">Directly classifying arbitrary photos is unreliable &mdash; many images contain multiple objects and backgrounds. PlasticID separates the two hard problems.</p>
        </div>

        <div class="pipeline">
            <div class="pipe-step">
                <span class="step-num mb-3">1</span>
                <h6 class="fw-bold mb-1">Input image</h6>
                <p class="small text-muted mb-0">JPEG or PNG up to 10 MB, any resolution.</p>
            </div>
            <div class="pipe-arrow left">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </div>
            <div class="pipe-step">
                <span class="step-num mb-3">2</span>
                <h6 class="fw-bold mb-1">Object detection</h6>
                <p class="small text-muted mb-0">YOLOv8n (COCO) locates objects &mdash; bottles, cups, bowls, vases &mdash; and draws bounding boxes.</p>
            </div>
            <div class="pipe-arrow left">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </div>
            <div class="pipe-step pipe-final">
                <span class="step-num mb-3">3</span>
                <h6 class="fw-bold mb-1">Resin classification</h6>
                <p class="small mb-0">The best object is cropped and passed to a YOLOv8 model fine-tuned on the 6 plastic classes.</p>
            </div>
        </div>

        <p class="text-muted small mt-4 text-center">
            <code>/v1/predict</code> runs all three stages and returns class, confidence, and bounding box per detection.
        </p>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="text-center mb-5">
            <div class="eyebrow grad mb-2">Supported Plastics</div>
            <h2 class="section-title">Six resin classes, clearly separated</h2>
        </div>
        <div class="table-responsive">
            <table class="table-modern">
                <thead>
                    <tr><th>ID</th><th>Resin Code</th><th>Typical Items</th></tr>
                </thead>
                <tbody>
                    <tr><td class="text-muted fw-semibold">0</td><td><span class="badge-resin">HDPE</span></td><td>Milk jugs, shampoo bottles, detergent containers</td></tr>
                    <tr><td class="text-muted fw-semibold">1</td><td><span class="badge-resin">LDPE</span></td><td>Plastic bags, squeeze bottles, film packaging</td></tr>
                    <tr><td class="text-muted fw-semibold">2</td><td><span class="badge-resin">PVC</span></td><td>Pipes, fittings, flooring, window frames</td></tr>
                    <tr><td class="text-muted fw-semibold">3</td><td><span class="badge-resin">PET</span></td><td>Drink bottles, food trays, textile fiber</td></tr>
                    <tr><td class="text-muted fw-semibold">4</td><td><span class="badge-resin">PP</span></td><td>Caps, lids, food containers, straws</td></tr>
                    <tr><td class="text-muted fw-semibold">5</td><td><span class="badge-resin">PS</span></td><td>Cutlery, cups, foam packaging, CD cases</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="text-center mb-5">
            <div class="eyebrow grad mb-2">Model &amp; Training</div>
            <h2 class="section-title">Built on YOLOv8, tuned for plastics</h2>
        </div>
        <div class="row g-4">
            <div class="col-md-6">
                <div class="card-modern">
                    <div class="card-body">
                        <div class="icon-tile">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a5 5 0 0 1 5 5c0 2-1 3-2 4l1 2a3 3 0 0 1-3 3h-2a3 3 0 0 1-3-3l1-2c-1-1-2-2-2-4a5 5 0 0 1 5-5Z"/><path d="M8 18h8M10 22h4"/></svg>
                        </div>
                        <h5 class="fw-bold mb-3">The classifier</h5>
                        <ul class="list-unstyled mb-0" style="line-height:2">
                            <li><span class="badge-resin me-2">YOLOv8</span>&nbsp;architecture, fine-tuned from <code>yolov8n.pt</code></li>
                            <li class="text-muted">Input size&nbsp; <code>640 &times; 640</code>&nbsp; px at inference</li>
                            <li class="text-muted">Adam optimizer, learning rate <code>0.001</code></li>
                            <li class="text-muted">Default <code>50</code> epochs, patience <code>10</code></li>
                            <li class="text-muted">Detections below confidence <code>0.25</code> are discarded</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card-modern">
                    <div class="card-body">
                        <div class="icon-tile">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h16M6 16l4-8 3 6 2-3 3 5"/><circle cx="18" cy="5" r="2.5"/></svg>
                        </div>
                        <h5 class="fw-bold mb-3">The dataset</h5>
                        <ul class="list-unstyled mb-0" style="line-height:2">
                            <li class="text-muted">Labeled images of plastic items per resin class</li>
                            <li class="text-muted">Split into <code>train</code> / <code>val</code> / <code>test</code> sets</li>
                            <li class="text-muted">Per-class detection accuracy tracked each run</li>
                            <li class="text-muted">Metrics (<code>mAP50</code>, <code>mAP50&ndash;95</code>, per-class AP) logged to CSV for comparison</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4">
            <div class="callout callout-note">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto"><path d="M20 12a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z"/><path d="M12 8v4M12 16h.01"/></svg>
                <div>
                    <strong>Training workflow.</strong>
                    Configure <code>plasticid/configs/train.yaml</code> and run
                    <code>python -m src.training.train</code>. The best checkpoint is copied to
                    <code>models/best.pt</code> automatically, ready for the API to serve.
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="text-center mb-5">
            <div class="eyebrow grad mb-2">Output Format</div>
            <h2 class="section-title">Structured, predictable JSON</h2>
        </div>
        <p class="text-center lead-muted mb-4">Each response carries a unique <code>job_id</code> plus one entry per detection.</p>
        <div class="code-block mx-auto" style="max-width: 680px">
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
        <p class="text-muted small mt-3 text-center">
            <code>bbox</code> is in pixel coordinates as <code>[x1, y1, x2, y2]</code>.
            <code>detected_object</code> is the source item found by the detector (e.g. <em>bottle</em>).
        </p>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="eyebrow grad mb-2">Limitations</div>
                <h2 class="section-title mb-3">When to expect the best results</h2>
                <div class="callout callout-warn mb-3">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
                    Best accuracy comes from <strong>single-item, well-lit</strong> photos on a plain background.
                </div>
                <ul class="lead-muted" style="line-height:2">
                    <li>Cluttered scenes or heavy occlusion reduce detection reliability.</li>
                    <li>Detections below <code>0.25</code> confidence are dropped, so not everything may be reported.</li>
                    <li>Inference runs on CPU &mdash; response time varies with image content and resolution.</li>
                </ul>
            </div>
            <div class="col-lg-5">
                <div class="cta-band p-4">
                    <h4 class="fw-bold mb-2">See it in action</h4>
                    <p class="mb-3" style="color:#b6c2da">Upload a photo on the playground &mdash; no sign-up needed.</p>
                    <a class="btn-grad" href="/portal/playground">Open Playground</a>
                </div>
            </div>
        </div>
    </div>
</section>

<?= view('portal/_footer') ?>
</body>
</html>