<?= view('portal/_head', ['title' => 'Get API Key']) ?>
<body>
<?= view('portal/_nav', ['active' => 'request']) ?>

<header class="page-hero">
    <div class="container">
        <span class="kicker">Get API key</span>
        <h1>A free key to integrate PlasticID</h1>
        <p class="lede">Generated in seconds, returned once, and stored hashed on our side.</p>
    </div>
</header>

<section class="section" data-reveal>
    <div class="container narrow">
        <?php if (isset($success) && $success): ?>

            <div class="panel" style="max-width:640px;margin:0 auto;text-align:center">
                <span style="display:inline-grid;place-items:center;width:60px;height:60px;border-radius:50%;background:var(--accent-soft);color:var(--accent-ink);margin-bottom:20px">
                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                </span>
                <p class="kicker">Key issued</p>
                <h2 style="font-size:1.6rem;margin-top:6px">Your key is ready</h2>
                <p class="u-muted" style="margin-top:10px">Hello, <strong><?= esc($name) ?></strong>. Copy it now; it will not be shown again.</p>

                <div class="key-box" style="margin-top:26px;text-align:left">
                    <div class="row">
                        <span class="label">API key</span>
                        <button type="button" class="code-copy" id="copy-key">Copy</button>
                    </div>
                    <div class="key-value" id="key-value"><?= esc($api_key) ?></div>
                </div>

                <div class="callout note" style="margin-top:20px;text-align:left">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 15.5h.01"/></svg>
                    <div>Send it in the <code>X-API-KEY</code> header on every request. See the <a href="/portal/docs">API docs</a>.</div>
                </div>

                <div class="actions" style="justify-content:center;margin-top:24px">
                    <a class="btn btn-primary" href="/portal/playground">Try the playground</a>
                    <a class="btn btn-secondary" href="/portal/request">Generate another</a>
                </div>
            </div>

        <?php else: ?>

            <div class="grid-2">
                <div>
                    <h2 style="font-size:1.4rem;max-width:28ch">Your key unlocks the full API</h2>
                    <dl class="spec-list" style="margin-top:18px">
                        <div class="spec-row">
                            <span class="spec-key">OWNER</span>
                            <div><dt>Exclusive to you</dt><dd>Predictions are tracked under your key, with your own quota.</dd></div>
                        </div>
                        <div class="spec-row">
                            <span class="spec-key">FREE</span>
                            <div><dt>Instant sign-up</dt><dd>Generated in one click, no card and no approval.</dd></div>
                        </div>
                        <div class="spec-row">
                            <span class="spec-key">SECURE</span>
                            <div><dt>Stored hashed</dt><dd>Keys are stored as SHA-256 hashes and never appear in URLs.</dd></div>
                        </div>
                    </dl>
                    <div class="callout note" style="margin-top:16px">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 15.5h.01"/></svg>
                        <div>Not sure yet? <a href="/portal/playground">Try the playground</a> first; it needs no key.</div>
                    </div>
                </div>

                <div>
                    <form class="panel" method="post" action="/portal/request">
                        <?= csrf_field() ?>
                        <p class="panel-title">Request a key</p>
                        <p class="panel-sub">Includes 100 requests per hour by default.</p>

                        <?php if (isset($error)): ?>
                            <div class="callout danger" style="margin-bottom:18px">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 15.5h.01"/></svg>
                                <div><?= esc($error) ?></div>
                            </div>
                        <?php endif ?>

                        <div class="field">
                            <label for="name">Name</label>
                            <input type="text" class="input" id="name" name="name" value="<?= esc($name ?? '') ?>" placeholder="Ada Lovelace" autocomplete="name" required>
                        </div>
                        <div class="field">
                            <label for="email">Email</label>
                            <input type="email" class="input" id="email" name="email" value="<?= esc($email ?? '') ?>" placeholder="ada@example.com" autocomplete="email" required>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:100%">
                            Generate API key
                            <svg class="arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </button>
                    </form>
                </div>
            </div>

        <?php endif ?>
    </div>
</section>

<section class="promo" data-reveal>
    <div class="container">
        <div class="inner">
            <div>
                <span class="kicker">Explore</span>
                <h2>Just here to explore?</h2>
                <p>Understand the science or test predictions before writing any code.</p>
            </div>
            <div class="actions">
                <a class="btn btn-primary" href="/portal/methodology">Read the methodology</a>
                <a class="btn btn-secondary" href="/portal/playground">Try the playground</a>
            </div>
        </div>
    </div>
</section>

<?= view('portal/_footer') ?>
</body>
</html>