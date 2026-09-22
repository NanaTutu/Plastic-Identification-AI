<?= view('portal/_head', ['title' => 'Get API Key']) ?>
<body>
<?= view('portal/_nav', ['active' => 'request']) ?>

<header class="page-hero text-center">
    <div class="container">
        <div class="eyebrow grad mb-2">Get API Key</div>
        <h1>A free key to integrate PlasticID</h1>
        <p class="sub mx-auto">Generated in seconds, returned once, and stored hashed on our side.</p>
    </div>
</header>

<section class="section">
    <div class="container" style="max-width: 960px">
        <?php if (isset($success) && $success): ?>

            <div class="card-modern" style="max-width:640px;margin:0 auto">
                <div class="card-body text-center py-5">
                    <div class="icon-tile mx-auto" style="width:70px;height:70px;border-radius:20px;background:linear-gradient(135deg,#0ea5e9,#34d399);color:#fff">
                        <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    </div>
                    <h2 class="fw-bold mt-4 mb-1">Done! Your key is ready</h2>
                    <p class="text-muted">Hello, <strong><?= esc($name) ?></strong>. Copy it now &mdash; it won't be shown again.</p>

                    <div class="key-reveal text-start mt-4">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="small" style="color:#9fb0cc">API KEY</span>
                            <button type="button" class="code-copy" id="copy-key" style="position:static">Copy</button>
                        </div>
                        <div class="key-value" id="key-value"><?= esc($api_key) ?></div>
                    </div>

                    <div class="callout callout-info text-start mt-4">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto"><path d="M12 2a5 5 0 0 1 5 5c0 2-1 3-2 4l1 2a3 3 0 0 1-3 3h-2a3 3 0 0 1-3-3l1-2c-1-1-2-2-2-4a5 5 0 0 1 5-5Z"/><path d="M8 18h8M10 22h4"/></svg>
                        <div>Send it in the <code>X-API-KEY</code> header on every request. See the <a href="/portal/docs">API docs</a>.</div>
                    </div>

                    <div class="d-flex flex-wrap justify-content-center gap-3 mt-4">
                        <a class="btn-grad" href="/portal/playground">Test It in the Playground</a>
                        <a class="btn-outline-dark" href="/portal/request">Generate Another</a>
                    </div>
                </div>
            </div>

        <?php else: ?>

            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="d-flex flex-column gap-3">
                        <div>
                            <div class="eyebrow grad mb-2">What you get</div>
                            <h2 class="fw-bold">Your key unlocks the full API</h2>
                        </div>
                        <?php
                            $perks = [
                                ['lock', 'Exclusive to you', 'Predictions are tracked under your key, with your own quota.'],
                                ['zap', 'Instant & free', 'Generated in one click — no card, no approval.'],
                                ['shield', 'Secure by default', 'Keys are stored as SHA-256 hashes; never echoed in URLs.'],
                            ];
                        ?>
                        <?php foreach ($perks as $perk): ?>
                            <div class="d-flex align-items-start gap-3">
                                <span class="icon-tile" style="flex:0 0 52px">
                                    <?php if ($perk[0] === 'lock'): ?>
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                                    <?php elseif ($perk[0] === 'zap'): ?>
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8Z"/></svg>
                                    <?php else: ?>
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.9 6.3 6.9.9-5 4.9 1.2 6.8L12 17.9 5.9 20.9 7.1 14.1 2 9.2l6.9-.9L12 2.9Z"/></svg>
                                    <?php endif ?>
                                </span>
                                <div>
                                    <strong><?= $perk[1] ?></strong>
                                    <p class="text-muted small mb-0"><?= $perk[2] ?></p>
                                </div>
                            </div>
                        <?php endforeach ?>
                        <div class="callout callout-note">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto"><path d="M20 12a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z"/><path d="M12 8v4M12 16h.01"/></svg>
                            <div>Not sure yet? <a href="/portal/playground">Try the playground</a> — it needs no key.</div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card-modern form-card">
                        <div class="card-body p-4">
                            <h5 class="fw-bold mb-1">Request a key</h5>
                            <p class="text-muted small mb-4">Includes 100 requests per hour by default.</p>

                            <?php if (isset($error)): ?>
                                <div class="callout callout-danger mb-3">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
                                    <div><?= esc($error) ?></div>
                                </div>
                            <?php endif ?>

                            <form method="post" action="/portal/request">
                                <?= csrf_field() ?>
                                <div class="mb-3">
                                    <label class="form-label" for="name">Name</label>
                                    <input type="text" class="form-control" id="name" name="name"
                                           value="<?= esc($name ?? '') ?>" placeholder="Jane Doe" required>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label" for="email">Email</label>
                                    <input type="email" class="form-control" id="email" name="email"
                                           value="<?= esc($email ?? '') ?>" placeholder="jane@example.com" required>
                                </div>
                                <button type="submit" class="btn-grad w-100 justify-content-center">
                                    Generate API Key
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        <?php endif ?>
    </div>
</section>

<section class="pb-5">
    <div class="container">
        <div class="cta-band py-5">
            <h2 class="fw-bold mb-2">Just here to explore?</h2>
            <p class="mb-4" style="color:#b6c2da">Understand the science or test predictions before writing any code.</p>
            <div class="d-flex flex-wrap justify-content-center gap-3">
                <a class="btn-grad" href="/portal/methodology">Read the Methodology</a>
                <a class="btn-ghost" href="/portal/playground">Open Playground</a>
            </div>
        </div>
    </div>
</section>

<?= view('portal/_footer') ?>
<script>
    (function () {
        var btn = document.getElementById('copy-key');
        var val = document.getElementById('key-value');
        if (btn && val && navigator.clipboard) {
            btn.addEventListener('click', function () {
                navigator.clipboard.writeText(val.textContent.trim()).then(function () {
                    btn.textContent = 'Copied';
                    btn.classList.add('copied');
                    setTimeout(function () { btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 1600);
                });
            });
        }
    })();
</script>
</body>
</html>