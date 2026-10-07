<?= view('portal/_head', ['title' => 'AI-Powered Plastic Classification']) ?>
<body>
<?= view('portal/_nav', ['active' => 'home']) ?>

<header class="hero">
    <div class="container">
        <div class="hero-copy">
            <span class="kicker">YOLOv8 two-stage pipeline</span>
            <h1>Identify plastic types<br>from a <span class="grad-text">single photo</span></h1>
            <p class="lede">PlasticID classifies <strong>HDPE, LDPE, PVC, PET, PP and PS</strong> waste with object detection followed by resin-level classification.</p>
            <div class="hero-actions">
                <a class="btn btn-primary" href="/portal/playground">
                    Try the playground
                    <svg class="arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
                <a class="btn btn-secondary" href="/portal/methodology">Read the methodology</a>
            </div>
            <div class="hero-meta">
                <div class="meta"><strong>6</strong><span>resin classes</span></div>
                <div class="meta"><strong>2</strong><span>model stages</span></div>
                <div class="meta"><strong>10 MB</strong><span>max image upload</span></div>
            </div>
        </div>

        <div class="hero-media" data-reveal>
            <div class="media-frame">
                <img class="photo" src="/assets/img/plastic-hero.jpg" alt="High-density polyethylene bottle photographed for the PlasticID dataset" width="640" height="480">
                <span class="conf-chip">
                    <span class="cv">HDPE</span>
                    <span>80.7% confidence</span>
                </span>
            </div>
            <div class="media-caption">
                <span>Sample image from the test set</span>
                <code>640 &times; 640 px in</code>
            </div>
        </div>
    </div>
</header>

<section class="section" data-reveal>
    <div class="container">
        <div class="section-head">
            <span class="kicker">How it works</span>
            <h2 class="title-lg">Three steps from photo to structured data</h2>
            <p class="lede">A purpose-built pipeline turns a raw photo into actionable, machine-readable output.</p>
        </div>

        <ol class="steps" style="margin-top:40px">
            <li>
                <span class="step-tick">01</span>
                <div>
                    <h3>Upload an image</h3>
                    <p>Send a JPEG or PNG (up to 10 MB) through the API, the playground, or a single curl command.</p>
                </div>
            </li>
            <li>
                <span class="step-tick">02</span>
                <div>
                    <h3>Detect, then classify</h3>
                    <p>A YOLOv8 model locates the object and draws a bounding box. The crop is passed to a fine-tuned model that identifies the resin.</p>
                </div>
            </li>
            <li>
                <span class="step-tick">03</span>
                <div>
                    <h3>Read the structured result</h3>
                    <p>The response carries the plastic type, a confidence score, the bounding box, and inference timing in a predictable JSON schema.</p>
                </div>
            </li>
        </ol>
    </div>
</section>

<section class="section band" data-reveal>
    <div class="container">
        <div class="section-head">
            <span class="kicker">Resin classes</span>
            <h2 class="title-lg">Six common plastics, one classifier</h2>
            <p class="lede">Every class maps to a standard resin identification code: the categories that matter most for recycling and waste sorting.</p>
        </div>
        <?= view('portal/_plastics') ?>
    </div>
</section>

<section class="section" data-reveal>
    <div class="container">
        <div class="section-head">
            <span class="kicker">Explore</span>
            <h2 class="title-lg">Everything you need to get started</h2>
        </div>

        <div class="bento" style="margin-top:36px">
            <div class="bento-card play">
                <div class="bento-media">
                    <span class="tag tag-accent">Live demo</span>
                    <img src="/assets/img/plastic-bento.jpg" alt="Plastic sample photographed for the PlasticID dataset" width="640" height="480">
                </div>
                <div class="bento-body">
                    <h3>Test the classifier live</h3>
                    <p class="lede">Upload a photo of plastic waste and see a real prediction. No API key required.</p>
                    <a class="btn btn-primary" href="/portal/playground">
                        Try the playground
                        <svg class="arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </a>
                </div>
            </div>
            <div class="bento-card method">
                <span class="kicker">Methodology</span>
                <h3>Under the hood</h3>
                <p class="lede">The two-stage pipeline, model training, dataset split, thresholds, and known limitations.</p>
                <a class="btn btn-ghost" href="/portal/methodology">
                    Read the methodology
                    <svg class="arrow" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
            </div>
            <div class="bento-card docs">
                <span class="kicker">API docs</span>
                <h3>Every endpoint</h3>
                <p class="lede">Authentication, request and response examples, error codes, and rate limits.</p>
                <a class="btn btn-ghost" href="/portal/docs">
                    Read the API docs
                    <svg class="arrow" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
            </div>
        </div>
    </div>
</section>

<section class="promo" data-reveal>
    <div class="container">
        <div class="inner">
            <div>
                <span class="kicker">Get started</span>
                <h2>Ready to classify plastic waste?</h2>
                <p>Try it with your own image in seconds, or grab a dedicated key and start building.</p>
            </div>
            <div class="actions">
                <a class="btn btn-primary" href="/portal/playground">Try the playground</a>
                <a class="btn btn-secondary" href="/portal/request">Get an API key</a>
            </div>
        </div>
    </div>
</section>

<?= view('portal/_footer') ?>
</body>
</html>